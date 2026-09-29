<?php

use App\Domain\Licensing\Actions\ReissueKey;
use App\Domain\Licensing\Actions\ReleaseDevice;
use App\Domain\Licensing\Actions\RenewLicence;
use App\Domain\Licensing\Actions\RevokeLicence;
use App\Domain\Licensing\Actions\SuspendLicence;
use App\Domain\Licensing\Data\RenewalTerm;
use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Models\LicenceAlert;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Actions\SuspendCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\Api\LicenceApiHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;
use Tests\Support\SsposDocs;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LicenceApiHelpers::class);

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    $this->withSigningKey();
    [$this->company, $this->licence] = $this->keyedTenant();
    $this->token = (string) $this->activateTill()->assertOk()->json('licenceToken');
});

test('an unchanged licence answers active with licenceToken null and records the check-in', function () {
    config(['licence.api.expiring_days' => 3]);
    $this->travel(1)->days();

    $this->validateTill($this->licence->id, $this->token, overrides: [
        'tillClockUtc' => '2026-10-06T08:58:00Z',
        'clockWatermarkUtc' => '2026-10-06T08:58:00Z',
        'lock' => ['locked' => false, 'reason' => null],
    ])->assertOk()
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('licenceToken', null)
        ->assertJsonPath('licence.licenceId', $this->licence->id)
        ->assertJsonPath('apiKey', null)
        ->assertJsonPath('portalTimeUtc', '2026-10-06T09:00:00Z')
        ->assertJsonPath('nextCheckAfterSeconds', 86400);

    $licence = $this->licence->fresh();
    expect($licence->last_validated_at?->toIso8601String())->toBe('2026-10-06T09:00:00+00:00')
        ->and($licence->till_clock_skew_seconds)->toBe(-120)
        ->and($licence->clock_watermark_at?->toIso8601String())->toBe('2026-10-06T08:58:00+00:00')
        ->and($licence->lock_locked)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'licence.lock_changed')->count())->toBe(0);
});

test('a new token when the till holds another token or does not trust our kid', function () {
    $other = $this->validateTill($this->licence->id, 'SSPOS1.old.token')->assertOk()->json('licenceToken');
    expect($other)->toStartWith('SSPOS1.');

    $this->validateTill($this->licence->id, $other)->assertOk()->assertJsonPath('licenceToken', null);

    $this->validateTill($this->licence->id, $other, overrides: ['trustedKids' => [SsposDocs::APPROVER_KID]])
        ->assertOk()->assertJsonPath('licenceToken', fn (?string $token) => is_string($token));
});

test('a renewal sends a new full token with the new expiry', function () {
    app(RenewLicence::class)->handle($this->licence->fresh(), RenewalTerm::month());

    $response = $this->validateTill($this->licence->id, $this->token)->assertOk()->assertJsonPath('status', 'active');
    $token = $this->verifyToken($response);

    expect($token->kind()?->value)->toBe('full')
        ->and($token->get('expiresAt'))->toBe($response->json('licence.expiresAt'))
        ->and($response->json('licence.expiresAt'))->not->toBe('2026-10-15T09:00:00Z');

    $this->validateTill($this->licence->id, $token->token)->assertOk()->assertJsonPath('licenceToken', null);
});

test('status matrix: expiring trial, ended (our grace), expired — expiresAt never includes grace days', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 09:00:00', 'UTC'));
    $this->validateTill($this->licence->id, $this->token)->assertOk()
        ->assertJsonPath('status', 'expiring')
        ->assertJsonPath('licence.expiresAt', '2026-10-12T09:00:00Z')
        ->assertJsonPath('messages.0.title', 'Trial ends soon')
        ->assertJsonPath('messages.0.showUntilUtc', '2026-10-12T09:00:00Z');

    // Past the trial's end, inside our 3 trial grace days: the till locks at expiresAt, so the status is expired
    // and the message says renewal is pending (ANSWERS-2026-09-29 §2). Only onlineCheck.graceDays is offline slack.
    $this->travelTo(CarbonImmutable::parse('2026-10-13 09:00:00', 'UTC'));
    $this->validateTill($this->licence->id, $this->token)->assertOk()
        ->assertJsonPath('status', 'expired')
        ->assertJsonPath('licence.expiresAt', '2026-10-12T09:00:00Z')
        ->assertJsonPath('messages.0.id', 'expired-20261012')
        ->assertJsonPath('messages.0.text', fn (string $text) => str_contains($text, 'Renewal pending'));

    $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00:01', 'UTC'));
    $this->validateTill($this->licence->id, 'SSPOS1.other.token')->assertOk()
        ->assertJsonPath('status', 'expired')
        ->assertJsonPath('licenceToken', null)
        ->assertJsonPath('nextCheckAfterSeconds', 3600)
        ->assertJsonPath('messages.0.level', 'critical');
});

test('the expiring window comes from config', function () {
    config(['licence.api.expiring_days' => 12]);

    $this->validateTill($this->licence->id, $this->token)->assertOk()->assertJsonPath('status', 'expiring');
});

test('status matrix: suspended licence, suspended account, revoked', function () {
    app(SuspendLicence::class)->handle($this->licence->fresh(), 'Card chargeback');
    $this->validateTill($this->licence->id, $this->token)->assertOk()
        ->assertJsonPath('status', 'suspended')
        ->assertJsonPath('licenceToken', null)
        ->assertJsonPath('messages.0.text', fn (string $text) => str_contains($text, 'Card chargeback'));

    [$other, $licence] = $this->keyedTenant('Corner Shop', 1, 'CRN', self::OTHER_KEY);
    $token = $this->activateTill(self::OTHER_KEY, self::OTHER_INSTALL, self::OTHER_CODE)->json('licenceToken');
    app(SuspendCompany::class)->handle($other, 'Unpaid');
    $this->validateTill($licence->id, $token, self::OTHER_INSTALL)->assertOk()->assertJsonPath('status', 'suspended');

    app(RevokeLicence::class)->handle($licence->fresh(), 'Shop sold');
    $this->validateTill($licence->id, $token, self::OTHER_INSTALL)->assertOk()->assertJsonPath('status', 'revoked');
});

test('admin Release: the old install gets released, the key activates elsewhere', function () {
    app(ReleaseDevice::class)->handle($this->licence->fresh());

    $this->validateTill($this->licence->id, $this->token)->assertOk()
        ->assertJsonPath('status', 'released')
        ->assertJsonPath('licence', null)
        ->assertJsonPath('licenceToken', null)
        ->assertJsonPath('nextCheckAfterSeconds', 3600)
        ->assertJsonPath('messages.0.id', 'released');

    $this->activateTill(install: self::OTHER_INSTALL, code: self::OTHER_CODE)->assertOk();
    expect($this->licence->fresh()->device_id)->toBe(self::OTHER_INSTALL);

    $this->validateTill($this->licence->id, $this->token)->assertOk()->assertJsonPath('status', 'released');
});

test('a reissued key releases the old install too', function () {
    app(ReissueKey::class)->handle($this->licence->fresh());

    $this->validateTill($this->licence->id, $this->token)->assertOk()->assertJsonPath('status', 'released');
});

test('the lock state is stored and a lock or unlock is audited', function () {
    $this->validateTill($this->licence->id, $this->token, overrides: ['lock' => ['locked' => true, 'reason' => 'clockTampering']])->assertOk();
    expect($this->licence->fresh()->lock_locked)->toBeTrue()->and($this->licence->fresh()->lock_reason)->toBe('clockTampering');

    $this->validateTill($this->licence->id, $this->token)->assertOk();
    expect($this->licence->fresh()->lock_locked)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'licence.lock_changed')->count())->toBe(2);
});

test('an unknown licence or another install is 404 key.not_found; another install raises an alert', function () {
    $this->validateTill('01K5T0Q8C4000000000000Y999', $this->token)->assertNotFound()->assertJsonPath('code', 'key.not_found');

    $this->validateTill($this->licence->id, $this->token, self::OTHER_INSTALL)->assertNotFound()->assertJsonPath('code', 'key.not_found');
    expect(LicenceAlert::withoutCompanyScope()->sole()->type)->toBe(LicenceAlertType::DeviceMismatch);
});

test('the install id may come from the header only', function () {
    $body = $this->validateBody($this->licence->id, $this->token);
    unset($body['installId']);

    $this->till('licence/validate', $body, $this->tillHeaders())->assertOk()->assertJsonPath('status', 'expiring');
});

test('bad bodies are 400 request.invalid', function () {
    $this->validateTill($this->licence->id, $this->token, overrides: ['tokenSha256' => 'ABC'])->assertStatus(400)->assertJsonPath('details.field', 'tokenSha256');
    $this->validateTill($this->licence->id, $this->token, overrides: ['trustedKids' => []])->assertStatus(400)->assertJsonPath('details.field', 'trustedKids');
    $this->validateTill($this->licence->id, $this->token, overrides: ['lock' => ['locked' => 'maybe', 'reason' => null]])->assertStatus(400)->assertJsonPath('details.field', 'lock.locked');
});
