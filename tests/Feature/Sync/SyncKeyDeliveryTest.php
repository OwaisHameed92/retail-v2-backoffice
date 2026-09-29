<?php

use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Sync\Actions\IssueSyncKey;
use App\Domain\Sync\Actions\RequestSyncKeyRotation;
use App\Domain\Sync\Actions\RevokeSyncKeys;
use App\Domain\Sync\Enums\SyncKeySource;
use App\Domain\Sync\Models\SyncKey;
use App\Domain\Sync\Support\SyncKeySecret;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\Api\LicenceApiHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LicenceApiHelpers::class);

/** Module 2.1 (contract v1.4.1 §17.3 step 3, ANSWERS §2): the branch's sync key rides in the licence replies. */
beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    $this->withSigningKey();
    config(['app.url' => 'https://portal.test', 'sync.hub_url' => null]);
    [$this->company, $this->licence] = $this->keyedTenant();
    withDashboardOn($this->licence);
});

function withDashboardOn(Licence $licence): Licence
{
    $licence->forceFill(['features' => ['loyalty', 'cloud_sync']])->save();

    return $licence;
}

function syncKeysOf(Licence $licence)
{
    return SyncKey::withoutCompanyScope()->where('branch_id', $licence->branch_id)->get();
}

function currentSyncKeyOf(Licence $licence): SyncKey
{
    return SyncKey::withoutCompanyScope()->where('branch_id', $licence->branch_id)->current()->sole();
}

test('the main till of a cloud_sync licence gets the branch sync key once, in the activate reply', function () {
    $response = $this->activateTill()->assertOk()
        ->assertJsonPath('licence.features', fn (array $features) => in_array('cloud_sync', $features, true) && in_array('loyalty', $features, true))
        ->assertJsonMissingPath('hubUrl');

    $apiKey = (string) $response->json('apiKey');
    $key = syncKeysOf($this->licence)->sole();

    expect(SyncKeySecret::looksValid($apiKey))->toBeTrue()
        ->and(strlen(SyncKeySecret::canonical($apiKey)))->toBe(35) // SSK + 32 base32 characters = 160 bits
        ->and($key->key_hash)->toBe(SyncKeySecret::hash($apiKey))
        ->and($key->key_last4)->toBe(substr($apiKey, -4))
        ->and($key->source)->toBe(SyncKeySource::Till)
        ->and($key->delivered_install_id)->toBe(self::INSTALL)
        ->and($this->schemaErrors($response, 'licence-activate-reply.schema.json'))->toBe([])
        ->and(json_encode(DB::table('sync_keys')->get()))->not->toContain(SyncKeySecret::canonical($apiKey))
        ->and(json_encode(DB::table('audit_logs')->get()))->not->toContain(SyncKeySecret::canonical($apiKey));

    $token = $this->verifyToken($response);
    // Tokens carry only the till's own feature names.
    expect($token->payload['features'])->toContain('loyalty', 'cloud_sync')
        ->and(array_diff($token->payload['features'], Feature::values()))->toBe([]);

    // The daily check does not send it again.
    $validate = $this->validateTill($this->licence->id, $response->json('licenceToken'))->assertOk()->assertJsonPath('apiKey', null);
    expect($this->schemaErrors($validate, 'validate-reply.schema.json'))->toBe([])
        ->and(syncKeysOf($this->licence))->toHaveCount(1);
});

test('no key without cloud_sync, and never to a secondary till', function () {
    $this->licence->forceFill(['features' => ['loyalty']])->save();
    $this->activateTill()->assertOk()->assertJsonMissingPath('apiKey');

    $second = withDashboardOn($this->giveKey($this->licenceOf($this->registerOf($this->branchOf($this->company), '02')), self::OTHER_KEY));
    $token = $this->activateTill(self::OTHER_KEY, self::OTHER_INSTALL, self::OTHER_CODE)->assertOk()->assertJsonMissingPath('apiKey')->json('licenceToken');
    $this->validateTill($second->id, $token, self::OTHER_INSTALL)->assertOk()->assertJsonPath('apiKey', null);

    expect(SyncKey::withoutCompanyScope()->count())->toBe(0);
});

test('an admin rotation reaches the main till at its next check; the old key keeps 7 days of grace', function () {
    $old = (string) ($first = $this->activateTill()->assertOk())->json('apiKey');
    app(RequestSyncKeyRotation::class)->handle($this->licence->branch);

    $new = (string) $this->validateTill($this->licence->id, $first->json('licenceToken'))->assertOk()->json('apiKey');

    expect($new)->not->toBe('')->not->toBe($old);
    $current = currentSyncKeyOf($this->licence);
    $replaced = syncKeysOf($this->licence)->first(fn (SyncKey $key) => ! $key->is($current));
    expect($replaced->replaced_at)->not->toBeNull()
        ->and($replaced->isUsable(CarbonImmutable::now()->addDays(6)))->toBeTrue()
        ->and($replaced->isUsable(CarbonImmutable::now()->addDays(7)))->toBeFalse()
        ->and($current->key_hash)->toBe(SyncKeySecret::hash($new))
        ->and($current->rotate_requested_at)->toBeNull();

    $this->validateTill($this->licence->id, $first->json('licenceToken'))->assertOk()->assertJsonPath('apiKey', null);
});

test('after a revoke no key is sent until an admin makes one', function () {
    $first = $this->activateTill()->assertOk();
    app(RevokeSyncKeys::class)->handle($this->licence->branch);

    $this->validateTill($this->licence->id, $first->json('licenceToken'), overrides: ['lastSyncAt' => null])->assertOk()->assertJsonPath('apiKey', null);
    expect(syncKeysOf($this->licence)->every(fn (SyncKey $key) => $key->revoked_at !== null))->toBeTrue();
});

test('a till that reports no sync gets a key when the current one was typed or went to another PC', function () {
    $token = $this->activateTill()->assertOk()->json('licenceToken');
    app(IssueSyncKey::class)->handle($this->licence->branch, SyncKeySource::Admin); // "Connect" key, not sent to the till

    $this->validateTill($this->licence->id, $token, overrides: ['lastSyncAt' => '2026-10-05T08:59:00Z'])->assertOk()->assertJsonPath('apiKey', null);
    $sent = $this->validateTill($this->licence->id, $token, overrides: ['lastSyncAt' => null])->assertOk()->json('apiKey');

    expect($sent)->toBeString()
        ->and(currentSyncKeyOf($this->licence)->key_hash)->toBe(SyncKeySecret::hash($sent))
        ->and(currentSyncKeyOf($this->licence)->delivered_install_id)->toBe(self::INSTALL);
    $this->validateTill($this->licence->id, $token, overrides: ['lastSyncAt' => null])->assertOk()->assertJsonPath('apiKey', null);
});

test('hubUrl is sent with the key only when sync runs on another https host', function () {
    config(['sync.hub_url' => 'https://portal.test/']);
    $this->activateTill()->assertOk()->assertJsonMissingPath('hubUrl');

    app(RequestSyncKeyRotation::class)->handle($this->licence->branch);
    config(['sync.hub_url' => 'https://hub.sspos.test']);
    $this->validateTill($this->licence->id, 'x')->assertOk()->assertJsonPath('hubUrl', 'https://hub.sspos.test');
});

test('a replayed activate answers with the same key but the cache never holds it in plain text', function () {
    $headers = $this->tillHeaders(idempotencyKey: '01K5T0Q8C4000000000000K001');
    $first = $this->till('licence/activate', $this->activateBody(), $headers)->assertOk();
    $replay = $this->till('licence/activate', $this->activateBody(), $headers)->assertOk()->assertHeader('Idempotency-Replayed', 'true');

    expect($replay->json('apiKey'))->toBe($first->json('apiKey'))
        ->and(syncKeysOf($this->licence))->toHaveCount(1);

    $stored = serialize((fn () => $this->storage)->call(Cache::store()->getStore()));
    expect($stored)->toContain('licence-api:idem:')
        ->not->toContain((string) $first->json('apiKey'))
        ->not->toContain(SyncKeySecret::canonical((string) $first->json('apiKey')));
});

test('deactivating the main till that was sent the branch key revokes and rotates it: apiKeyRevoked true (v1.4.1)', function () {
    $apiKey = (string) $this->activateTill()->assertOk()->json('apiKey');
    $sent = currentSyncKeyOf($this->licence);

    $reply = $this->deactivateTill()->assertOk()->assertJsonPath('apiKeyRevoked', true)->assertJsonPath('seat', 'deactivated');
    expect($this->schemaErrors($reply, 'deactivate-reply.schema.json'))->toBe([])
        ->and($sent->fresh()->revoked_at)->not->toBeNull()
        ->and($sent->fresh()->isUsable(CarbonImmutable::now()))->toBeFalse()
        ->and(SyncKeySecret::hash($apiKey))->toBe($sent->key_hash);

    // A new current key replaces it, sent to nobody yet; the old PC's key is dead.
    $replacement = currentSyncKeyOf($this->licence);
    expect($replacement->id)->not->toBe($sent->id)
        ->and($replacement->delivered_install_id)->toBeNull();

    // Deactivating twice gives the same reply.
    $this->deactivateTill()->assertOk()->assertJsonPath('apiKeyRevoked', true);

    // The till (or its replacement PC) activates again and gets a fresh key of its own.
    $again = (string) $this->activateTill()->assertOk()->json('apiKey');
    expect(SyncKeySecret::looksValid($again))->toBeTrue()->and($again)->not->toBe($apiKey)
        ->and(currentSyncKeyOf($this->licence)->delivered_install_id)->toBe(self::INSTALL);
});

test('deactivating a till that never got the branch key leaves the key alone: apiKeyRevoked false', function () {
    $this->licence->forceFill(['features' => ['loyalty']])->save();
    $admin = app(IssueSyncKey::class)->handle($this->licence->branch, SyncKeySource::Admin);
    $this->activateTill()->assertOk()->assertJsonMissingPath('apiKey');

    $this->deactivateTill()->assertOk()->assertJsonPath('apiKeyRevoked', false);

    expect(currentSyncKeyOf($this->licence)->key_hash)->toBe(SyncKeySecret::hash($admin))
        ->and(currentSyncKeyOf($this->licence)->revoked_at)->toBeNull();
});
