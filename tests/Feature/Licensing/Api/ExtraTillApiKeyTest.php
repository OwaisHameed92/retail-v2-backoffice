<?php

use App\Domain\Licensing\Models\Licence;
use App\Domain\Sync\Actions\RequestSyncKeyRotation;
use App\Domain\Sync\Models\SyncKey;
use App\Domain\Tenancy\Actions\SetMainTill;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\Api\LicenceApiHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LicenceApiHelpers::class);

/*
 * Till 0.1.53 pack (ANSWERS-2026-10-06-b point 3): the branch's sync key goes to the shop's main (or only) till only.
 * An extra till (a per-till licence whose register is not the main till) never gets `apiKey` in licence/activate
 * (left out) or licence/validate (null), whatever would make the main till get one (no key yet, a rotation, a till
 * reporting no sync). SyncKeyDelivery::eligible.
 */
beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-08 09:00:00', 'UTC'));
    $this->withSigningKey();
    config(['app.url' => 'https://portal.test', 'sync.hub_url' => 'https://hub.sspos.test']);
    [$this->company, $this->main] = $this->keyedTenant();
    $this->extra = $this->giveKey($this->licenceOf($this->registerOf($this->branchOf($this->company), '02')), self::OTHER_KEY);

    foreach ([$this->main, $this->extra] as $licence) {
        $licence->forceFill(['features' => ['loyalty', 'cloud_sync']])->save();
    }
});

function extraTillKeys(Licence $licence)
{
    return SyncKey::withoutCompanyScope()->where('branch_id', $licence->branch_id)->get();
}

test('the extra till activating first gets no apiKey (or hubUrl); the main till then gets the branch key', function () {
    expect($this->extra->register->is_main_till)->toBeFalse()
        ->and($this->main->register->is_main_till)->toBeTrue();

    $extra = $this->activateTill(self::OTHER_KEY, self::OTHER_INSTALL, self::OTHER_CODE)->assertOk()
        ->assertJsonMissingPath('apiKey')->assertJsonMissingPath('hubUrl');
    expect(extraTillKeys($this->main))->toHaveCount(0);

    // Validate with "no sync yet" (the case that sends the main till a key): still nothing for the extra till.
    $this->validateTill($this->extra->id, $extra->json('licenceToken'), self::OTHER_INSTALL, ['lastSyncAt' => null])
        ->assertOk()->assertJsonPath('apiKey', null)->assertJsonMissingPath('hubUrl');

    $main = $this->activateTill()->assertOk();
    expect($main->json('apiKey'))->toStartWith('SSK-')
        ->and($main->json('hubUrl'))->toBe('https://hub.sspos.test')
        ->and(extraTillKeys($this->main))->toHaveCount(1);
});

test('a rotation and a "no sync" check reach the main till only', function () {
    $mainToken = $this->activateTill()->assertOk()->json('licenceToken');
    $extraToken = $this->activateTill(self::OTHER_KEY, self::OTHER_INSTALL, self::OTHER_CODE)->assertOk()->assertJsonMissingPath('apiKey')->json('licenceToken');

    app(RequestSyncKeyRotation::class)->handle($this->main->branch);

    $this->validateTill($this->extra->id, $extraToken, self::OTHER_INSTALL, ['lastSyncAt' => null])->assertOk()->assertJsonPath('apiKey', null);
    expect(extraTillKeys($this->main)->sole(fn (SyncKey $key) => $key->isCurrent())->rotate_requested_at)->not->toBeNull();

    $rotated = $this->validateTill($this->main->id, $mainToken)->assertOk()->json('apiKey');
    expect($rotated)->toStartWith('SSK-');
});

test('when the main till changes, the new main till gets the key and the old one (now extra) does not', function () {
    $mainToken = $this->activateTill()->assertOk()->json('licenceToken');
    $extraToken = $this->activateTill(self::OTHER_KEY, self::OTHER_INSTALL, self::OTHER_CODE)->assertOk()->json('licenceToken');

    app(SetMainTill::class)->handle($this->extra->register);
    app(RequestSyncKeyRotation::class)->handle($this->main->branch);

    $this->validateTill($this->main->id, $mainToken, overrides: ['lastSyncAt' => null])->assertOk()->assertJsonPath('apiKey', null);
    $sent = $this->validateTill($this->extra->id, $extraToken, self::OTHER_INSTALL, ['lastSyncAt' => null])->assertOk()->json('apiKey');

    expect($sent)->toStartWith('SSK-')
        ->and(extraTillKeys($this->main)->sole(fn (SyncKey $key) => $key->isCurrent())->delivered_install_id)->toBe(self::OTHER_INSTALL);
});
