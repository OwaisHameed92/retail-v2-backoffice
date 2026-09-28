<?php

use App\Domain\Licensing\Signing\Actions\RotateSigningKey;
use App\Domain\Licensing\Signing\Jwks;
use App\Domain\Licensing\Signing\KeyStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\Api\LicenceApiHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LicenceApiHelpers::class);

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00:00', 'UTC'));
    $this->withSigningKey();
});

test('every licence endpoint needs X-SSPOS-Licence-Contract: 1', function (string $method, string $path, array $headers) {
    $this->keyedTenant();

    $this->json($method, "/api/v1/licence/{$path}", $method === 'GET' ? [] : $this->activateBody(), $headers)
        ->assertStatus(409)
        ->assertJsonPath('code', 'contract.unsupported')
        ->assertJsonPath('message', 'This version of the till is not supported. Please update SSPOS.')
        ->assertJsonStructure(['code', 'message', 'traceId', 'retryAfterSeconds', 'rejectedKey']);
})->with([
    'activate, missing' => ['POST', 'activate', []],
    'check-in, version 2' => ['POST', 'check-in', ['X-SSPOS-Licence-Contract' => '2']],
    'deactivate, empty' => ['POST', 'deactivate', ['X-SSPOS-Licence-Contract' => '']],
    'keys, missing' => ['GET', 'keys', []],
]);

test('GET keys returns the JWKS: active and retired keys that still verify, no secrets', function () {
    $first = app(KeyStore::class)->active()->kid;
    $second = app(RotateSigningKey::class)->handle()['key']->kid;

    $response = $this->getJson('/api/v1/licence/keys', $this->tillHeaders())
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(app(Jwks::class)->current());

    expect(collect($response->json('keys'))->pluck('kid')->all())->toEqualCanonicalizing([$first, $second])
        ->and($response->json('keys.0'))->toHaveKeys(['kid', 'kty', 'crv', 'x', 'use'])
        ->and($response->json('keys.0.kty'))->toBe('OKP')
        ->and($response->json('keys.0.crv'))->toBe('Ed25519')
        ->and(strlen((string) $response->json('keys.0.x')))->toBe(43)
        ->and($response->getContent())->not->toContain('secret')->not->toContain('"d"');
});

test('the key is never accepted in the query string', function () {
    [, $licence] = $this->keyedTenant();

    $this->postJson('/api/v1/licence/activate?licenceKey='.urlencode(self::KEY).'&deviceId=PC-1', [], $this->tillHeaders())
        ->assertStatus(400)
        ->assertJsonPath('code', 'request.invalid');

    // Even with a valid body, a query string is refused.
    $this->postJson('/api/v1/licence/activate?x=1', $this->activateBody(), $this->tillHeaders())->assertStatus(400);

    expect($licence->refresh()->device_id)->toBeNull();
});

test('10 requests a minute per key, then 429 rate_limited with retryAfterSeconds', function () {
    $this->keyedTenant();

    foreach (range(1, 10) as $i) {
        $this->till('check-in', $this->checkInBody(device: "PC-{$i}"))->assertForbidden();
    }

    // Any spelling of the same key shares the bucket.
    $this->till('check-in', $this->checkInBody(key: 'ssp7k2q9dmf3xrap8t5'))
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'rate_limited')
        ->assertJsonPath('retryAfterSeconds', fn ($seconds) => is_int($seconds) && $seconds >= 1 && $seconds <= 60);

    // Another key from the same IP is still fine.
    $this->till('check-in', $this->checkInBody(key: self::OTHER_KEY))->assertNotFound();

    $this->travel(61)->seconds();
    $this->till('check-in', $this->checkInBody())->assertForbidden();
});

test('30 requests a minute per IP across keys', function () {
    foreach (range(1, 30) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->getJson('/api/v1/licence/keys', $this->tillHeaders())->assertOk();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->getJson('/api/v1/licence/keys', $this->tillHeaders())
        ->assertStatus(429)
        ->assertJsonPath('code', 'rate_limited');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.8'])->getJson('/api/v1/licence/keys', $this->tillHeaders())->assertOk();
});

test('the till API is JSON only with the standard error body', function () {
    $this->postJson('/api/v1/licence/nope', [], $this->tillHeaders())
        ->assertNotFound()
        ->assertJsonStructure(['code', 'message', 'traceId', 'retryAfterSeconds', 'rejectedKey']);

    $this->getJson('/api/v1/licence/activate', $this->tillHeaders())->assertStatus(405)->assertJsonPath('code', 'method_not_allowed');
});
