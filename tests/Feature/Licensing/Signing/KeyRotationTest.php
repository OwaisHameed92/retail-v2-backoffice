<?php

use App\Domain\Licensing\Signing\Actions\GenerateSigningKey;
use App\Domain\Licensing\Signing\Actions\PruneSigningKeys;
use App\Domain\Licensing\Signing\Actions\RotateSigningKey;
use App\Domain\Licensing\Signing\Enums\SigningKeyStatus;
use App\Domain\Licensing\Signing\Exceptions\ActiveSigningKeyExists;
use App\Domain\Licensing\Signing\Exceptions\NoActiveSigningKey;
use App\Domain\Licensing\Signing\Exceptions\UnknownKey;
use App\Domain\Licensing\Signing\Jwks;
use App\Domain\Licensing\Signing\KeyStore;
use App\Domain\Licensing\Signing\LicenceTokenSigner;
use App\Domain\Licensing\Signing\LicenceTokenVerifier;
use App\Domain\Licensing\Signing\Models\LicenceSigningKey;
use App\Domain\Shared\Models\AuditLog;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00:00', 'UTC'));
});

it('names the first key lk<year>-01 and makes it the only active key', function () {
    $key = app(GenerateSigningKey::class)->handle();

    expect($key->kid)->toBe('lk2026-01')
        ->and($key->canSign())->toBeTrue()
        ->and(LicenceSigningKey::query()->where('is_active', true)->count())->toBe(1);
});

it('refuses a second first key unless forced', function () {
    app(GenerateSigningKey::class)->handle();

    expect(fn () => app(GenerateSigningKey::class)->handle())->toThrow(ActiveSigningKeyExists::class);

    $forced = app(GenerateSigningKey::class)->handle(force: true);

    expect($forced->kid)->toBe('lk2026-02')
        ->and(LicenceSigningKey::query()->where('is_active', true)->pluck('kid')->all())->toBe(['lk2026-02']);
});

it('refuses to rotate when there is no key yet', function () {
    app(RotateSigningKey::class)->handle();
})->throws(NoActiveSigningKey::class);

it('rotates: new tokens use the new kid, old tokens still verify', function () {
    app(GenerateSigningKey::class)->handle();
    $old = app(LicenceTokenSigner::class)->sign(['lic' => 'old']);

    $this->travel(1)->days();
    $result = app(RotateSigningKey::class)->handle();
    $new = app(LicenceTokenSigner::class)->sign(['lic' => 'new']);

    $verifier = app(LicenceTokenVerifier::class);

    expect($result['key']->kid)->toBe('lk2026-02')
        ->and($result['retired'])->toBe(['lk2026-01'])
        ->and($verifier->verify($new)->kid())->toBe('lk2026-02')
        ->and($verifier->verify($old)->kid())->toBe('lk2026-01')
        ->and($verifier->verify($old)->claim('lic'))->toBe('old')
        ->and(LicenceSigningKey::query()->where('is_active', true)->pluck('kid')->all())->toBe(['lk2026-02']);
});

it('wipes the secret of a retired key', function () {
    app(GenerateSigningKey::class)->handle();
    app(RotateSigningKey::class)->handle();

    $retired = LicenceSigningKey::query()->where('kid', 'lk2026-01')->firstOrFail();

    expect($retired->getRawOriginal('secret_key'))->toBeNull()
        ->and($retired->is_active)->toBeFalse()
        ->and($retired->retired_at)->not->toBeNull()
        ->and($retired->toSigningKey()->canSign())->toBeFalse();
});

it('keeps old tokens verifying until the keep period ends, then prune removes the key', function () {
    config(['licence.signing_keys.retired_keep_days' => 60]);
    app(GenerateSigningKey::class)->handle();
    $old = app(LicenceTokenSigner::class)->sign([]);
    app(RotateSigningKey::class)->handle();

    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00:00', 'UTC')->addDays(60)->subSecond());
    expect(app(LicenceTokenVerifier::class)->verify($old)->kid())->toBe('lk2026-01')
        ->and(app(PruneSigningKeys::class)->handle())->toBe([]);

    $this->travel(1)->seconds();
    expect(fn () => app(LicenceTokenVerifier::class)->verify($old))->toThrow(UnknownKey::class)
        ->and(collect(app(Jwks::class)->current()['keys'])->pluck('kid')->all())->toBe(['lk2026-02']);

    expect(app(PruneSigningKeys::class)->handle())->toBe(['lk2026-01'])
        ->and(LicenceSigningKey::query()->pluck('kid')->all())->toBe(['lk2026-02'])
        ->and(fn () => app(LicenceTokenVerifier::class)->verify($old))->toThrow(UnknownKey::class);
});

it('never prunes the active key, however old', function () {
    app(GenerateSigningKey::class)->handle();
    $this->travel(5)->years();

    expect(app(PruneSigningKeys::class)->handle())->toBe([])
        ->and(app(KeyStore::class)->active()->kid)->toBe('lk2026-01');
});

it('never reuses a kid after pruning and restarts numbering each year', function () {
    app(GenerateSigningKey::class)->handle();          // lk2026-01
    app(RotateSigningKey::class)->handle();            // lk2026-02
    $this->travel(61)->days();
    app(PruneSigningKeys::class)->handle();            // lk2026-01 gone
    $third = app(RotateSigningKey::class)->handle();   // lk2026-03, not 02 again

    $this->travelTo(CarbonImmutable::parse('2027-01-02 00:00:00', 'UTC'));
    $nextYear = app(RotateSigningKey::class)->handle();

    expect($third['key']->kid)->toBe('lk2026-03')
        ->and($nextYear['key']->kid)->toBe('lk2027-01');
});

it('reports key status', function () {
    app(GenerateSigningKey::class)->handle();
    app(RotateSigningKey::class)->handle();
    $store = app(KeyStore::class);

    $statuses = fn () => collect($store->all())->mapWithKeys(fn ($k) => [$k->kid => $k->status(now(), $store->keepDays())])->all();

    expect($statuses())->toBe(['lk2026-02' => SigningKeyStatus::Active, 'lk2026-01' => SigningKeyStatus::Retired]);

    $this->travel(60)->days();

    expect($statuses())->toBe(['lk2026-02' => SigningKeyStatus::Active, 'lk2026-01' => SigningKeyStatus::Expired]);
});

it('publishes a JWKS with the active key first, then retired keys that still verify', function () {
    app(GenerateSigningKey::class)->handle();
    $this->travel(1)->minutes();
    app(RotateSigningKey::class)->handle();

    $jwks = app(Jwks::class)->current();
    $active = app(KeyStore::class)->active();

    expect(array_keys($jwks))->toBe(['keys'])
        ->and($jwks['keys'])->toHaveCount(2)
        ->and($jwks['keys'][0])->toBe(['kid' => 'lk2026-02', 'kty' => 'OKP', 'crv' => 'Ed25519', 'x' => $active->x(), 'use' => 'sig'])
        ->and($jwks['keys'][1]['kid'])->toBe('lk2026-01')
        ->and($jwks['keys'][1]['x'])->toMatch('/^[A-Za-z0-9_-]{43}$/');

    foreach ($jwks['keys'] as $jwk) {
        expect(array_keys($jwk))->toBe(['kid', 'kty', 'crv', 'x', 'use']);
    }
});

it('publishes an empty JWKS when there are no keys', function () {
    expect(app(Jwks::class)->current())->toBe(['keys' => []]);
});

it('audits generate, rotate and prune without key material', function () {
    app(GenerateSigningKey::class)->handle();
    app(RotateSigningKey::class)->handle();
    $this->travel(61)->days();
    app(PruneSigningKeys::class)->handle();

    expect(AuditLog::query()->pluck('action')->all())
        ->toEqualCanonicalizing(['licence_signing_key.generated', 'licence_signing_key.rotated', 'licence_signing_key.pruned'])
        ->and(AuditLog::query()->where('action', 'licence_signing_key.rotated')->value('meta'))
        ->toMatchArray(['kid' => 'lk2026-02', 'retiredKids' => ['lk2026-01']]);
});
