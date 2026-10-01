<?php

use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Models\LicenceAlert;
use App\Domain\Sync\Actions\RequestSyncKeyRotation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\Api\LicenceApiHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;
use Tests\Support\SsposDocs;

/*
 * Security review 7.3, H2 and L1: the till APIs need the till's proof (contract v1.4.1 §17.15.2, §17.7), and the
 * buckets keyed on a caller-chosen install id have a per-IP ceiling.
 */
uses(TenantTestHelpers::class, LicensingTestHelpers::class, LicenceApiHelpers::class);

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    $this->withSigningKey();
    config(['app.url' => 'https://portal.test', 'sync.hub_url' => null]);
    [$this->company, $this->licence] = $this->keyedTenant();
    $this->licence->forceFill(['features' => ['loyalty', 'cloud_sync']])->save();
    $activated = $this->activateTill()->assertOk();
    $this->token = (string) $activated->json('licenceToken');
    $this->apiKey = (string) $activated->json('apiKey');
});

function alertsOf(Licence $licence, LicenceAlertType $type): int
{
    return LicenceAlert::withoutCompanyScope()->where('licence_id', $licence->id)->where('type', $type->value)->count();
}

test('H2: validate with the right ids but a token we never issued is 404, records nothing and sends no sync key', function () {
    app(RequestSyncKeyRotation::class)->handle($this->licence->branch);
    $before = $this->licence->fresh()->last_validated_at;
    $this->travel(1)->hours();

    $this->validateTill($this->licence->id, 'SSPOS1.copied.ids')
        ->assertNotFound()->assertJsonPath('code', 'key.not_found')->assertJsonMissingPath('apiKey');

    expect($this->licence->fresh()->last_validated_at?->toIso8601String())->toBe($before?->toIso8601String())
        ->and(alertsOf($this->licence, LicenceAlertType::TokenMismatch))->toBe(1);

    // The real till still checks in, and gets the rotated key.
    $this->validateTill($this->licence->id, $this->token)->assertOk()->assertJsonPath('apiKey', fn ($key) => is_string($key));
});

test('H2: a till whose reply with a new token was lost still validates with the token before it, never an older one', function () {
    $untrusted = ['trustedKids' => [SsposDocs::APPROVER_KID], 'approverKids' => ['k00000000']];
    $this->travel(5)->seconds();
    $lost = (string) $this->validateTill($this->licence->id, $this->token, overrides: $untrusted)->assertOk()->json('licenceToken');

    // The reply never arrived: the till still holds the activation token, and gets another new one.
    $this->travel(5)->seconds();
    $second = (string) $this->validateTill($this->licence->id, $this->token, overrides: $untrusted)->assertOk()->json('licenceToken');
    expect($second)->not->toBe($lost)->and($lost)->not->toBe($this->token);

    // That one arrived: the till now holds $second; the activation token is still the previous one.
    $this->validateTill($this->licence->id, $second)->assertOk()->assertJsonPath('licenceToken', null);
    $this->validateTill($this->licence->id, $lost)->assertNotFound();
});

test('H2: the main till we sent the branch key must send it on deactivate; without it nothing is released', function () {
    $this->deactivateTill()->assertStatus(401)->assertJsonPath('code', 'auth.invalid_key');
    $this->deactivateTill(apiKey: 'SSK-0000-0000-0000-0000-0000-0000-0000-0000')->assertStatus(401);

    expect($this->licence->fresh()->device_id)->toBe(self::INSTALL)
        ->and(alertsOf($this->licence, LicenceAlertType::DeviceMismatch))->toBe(1);

    $this->deactivateTill(apiKey: $this->apiKey)->assertOk()->assertJsonPath('seat', 'deactivated');
    expect($this->licence->fresh()->device_id)->toBeNull()
        ->and(alertsOf($this->licence, LicenceAlertType::TillDeactivated))->toBe(1);
});

test('H2: a second till (no branch key) deactivates by its ids, and staff get a tillDeactivated alert', function () {
    $second = Licence::withoutCompanyScope()->where('branch_id', $this->licence->branch_id)->whereKeyNot($this->licence->id)->sole();
    $this->giveKey($second, self::OTHER_KEY);
    $this->activateTill(self::OTHER_KEY, self::OTHER_INSTALL, self::OTHER_CODE)->assertOk()->assertJsonMissingPath('apiKey');

    $this->deactivateTill(self::OTHER_TILL_REGISTER, self::OTHER_INSTALL)->assertOk()->assertJsonPath('apiKeyRevoked', false);
    $this->deactivateTill(self::OTHER_TILL_REGISTER, self::OTHER_INSTALL)->assertOk();

    expect(alertsOf($second, LicenceAlertType::TillDeactivated))->toBe(1);
});

test('H2: deactivate has its own per-IP limit', function () {
    config(['licence.api.rate_limits.deactivate_per_ip_per_hour' => 2]);

    $this->deactivateTill('01K5T0Q8C4000000000000R901', '01K5T0Q8C4000000000000J901')->assertNotFound();
    $this->deactivateTill('01K5T0Q8C4000000000000R902', '01K5T0Q8C4000000000000J902')->assertNotFound();
    $this->deactivateTill('01K5T0Q8C4000000000000R903', '01K5T0Q8C4000000000000J903')->assertStatus(429)->assertJsonPath('code', 'rate.limited');
});

test('L1: validate from many made-up install ids hits the per-IP ceiling', function () {
    config(['licence.api.rate_limits.per_ip_per_hour' => 3]);

    foreach (['J911', 'J912', 'J913'] as $suffix) {
        $this->validateTill($this->licence->id, 'x', '01K5T0Q8C4000000000000'.$suffix)->assertNotFound();
    }

    $this->validateTill($this->licence->id, $this->token)->assertStatus(429)->assertJsonPath('code', 'rate.limited');
});

test('L1: wrong licence keys from many made-up install ids hit the per-IP wrong-key limit', function () {
    config(['licence.api.rate_limits.wrong_keys_per_ip' => 2, 'licence.api.rate_limits.activate_per_ip_per_hour' => 100]);

    $this->activateTill(self::OTHER_KEY, '01K5T0Q8C4000000000000J921')->assertNotFound();
    $this->activateTill(self::OTHER_KEY, '01K5T0Q8C4000000000000J922')->assertNotFound();
    $this->activateTill(self::OTHER_KEY, '01K5T0Q8C4000000000000J923')->assertStatus(429)->assertJsonPath('code', 'activation.too_many_attempts');
});
