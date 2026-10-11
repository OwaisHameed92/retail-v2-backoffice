<?php

use App\Domain\Sync\Actions\IssueSyncKey;
use App\Domain\Sync\Actions\RequestSyncKeyRotation;
use App\Domain\Sync\Enums\SyncKeySource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\Api\LicenceApiHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LicenceApiHelpers::class)->group('audit-sync');

/*
 * Sync audit 2026-10-11 (docs/audit/2026-10-11-sync.md): sync key delivery and release through the licence API.
 * Each test proves one finding (SYNC-n) and FAILS until it is fixed.
 */

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    $this->withSigningKey();
    config(['app.url' => 'https://portal.test', 'sync.hub_url' => null]);
    [$this->company, $this->licence] = $this->keyedTenant();

    $this->hello = fn (string $key) => $this->getJson('/api/v1/sync/hello', [
        'Authorization' => 'Bearer '.$key, 'X-SSPOS-Contract' => '1', 'X-SSPOS-App-Version' => '3.0.412',
        'X-SSPOS-Company-Id' => self::TILL_COMPANY, 'X-SSPOS-Branch-Id' => self::TILL_BRANCH, 'X-SSPOS-Register-Id' => self::TILL_REGISTER,
    ]);
});

test('SYNC-3: a rotated sync key whose validate reply was lost is sent again before the old key dies', function () {
    $this->licence->forceFill(['features' => ['loyalty', 'cloud_sync']])->save();
    $activated = $this->activateTill()->assertOk();
    $old = (string) $activated->json('apiKey');
    $token = (string) $activated->json('licenceToken');
    ($this->hello)($old)->assertOk();

    app(RequestSyncKeyRotation::class)->handle($this->licence->branch);

    // The daily check carries the new key, but the reply never reaches the till (timeout, network drop).
    $lost = $this->validateTill($this->licence->id, $token, overrides: ['lastSyncAt' => '2026-10-05T08:59:00Z'])->assertOk();
    expect($lost->json('apiKey'))->not->toBeNull();

    // The next check: the till still holds (and syncs with) the old key.
    $this->travel(1)->days();
    ($this->hello)($old)->assertOk();
    $next = $this->validateTill($this->licence->id, $token, overrides: ['lastSyncAt' => now()->utc()->format('Y-m-d\TH:i:s\Z')])->assertOk();

    // Expected: the (never used) current key is sent again. Actual: apiKey null on every later check; after the
    // 7-day grace the old key stops (401 auth.key_revoked) and the shop stops syncing until staff rotate again.
    expect($next->json('apiKey'))->not->toBeNull();
});

test('SYNC-4: a main till linked with a typed sync key (Connect) must prove the key to be released, and its key is revoked', function () {
    // Activated before the dashboard was on: no key in the activate reply.
    $this->licence->forceFill(['features' => ['loyalty']])->save();
    $token = (string) $this->activateTill()->assertOk()->json('licenceToken');

    // The owner adds the dashboard and pastes the key staff generated (SIMPLE-SETUP route B, Connect).
    $this->licence->forceFill(['features' => ['loyalty', 'cloud_sync']])->save();
    $key = app(IssueSyncKey::class)->handle($this->licence->branch, SyncKeySource::Admin);
    ($this->hello)($key)->assertOk();
    $this->validateTill($this->licence->id, $token, overrides: ['lastSyncAt' => '2026-10-05T09:00:00Z'])->assertOk()->assertJsonPath('apiKey', null);

    // devices/deactivate from anyone who knows the install id and register id, without the branch key.
    $released = $this->deactivateTill();

    // Expected: 401 auth.invalid_key (the till holds the branch key, §17.7 / H2), and once released the key is
    // revoked so a restored old PC cannot push. Actual: 200, apiKeyRevoked false, the key still works.
    expect($released->status())->toBe(401);
    ($this->hello)($key)->assertStatus(401);
});
