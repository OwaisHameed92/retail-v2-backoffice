<?php

use App\Domain\Shared\Models\AuditLog;
use App\Domain\Shared\Support\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\Api\LicenceApiHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LicenceApiHelpers::class);

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    $this->withSigningKey();
    [$this->company, $this->licence] = $this->keyedTenant();
});

test('every endpoint needs X-SSPOS-Contract: 1, else 409 contract.unsupported', function (string $path, ?string $contract) {
    $headers = $this->tillHeaders();
    $contract === null ? $headers = array_diff_key($headers, ['X-SSPOS-Contract' => 1]) : $headers['X-SSPOS-Contract'] = $contract;

    $this->postJson("/api/v1/{$path}", [], $headers)->assertStatus(409)
        ->assertJsonPath('code', 'contract.unsupported')
        ->assertJsonPath('details.supportedContracts', [1])
        ->assertHeader('X-SSPOS-Contract', '1');
})->with([
    'activate' => ['licence/activate', null],
    'validate' => ['licence/validate', '2'],
    'deactivate' => ['devices/deactivate', 'v1'],
]);

test('replies echo the contract and send Date and no-store', function () {
    $this->activateTill()->assertOk()
        ->assertHeader('X-SSPOS-Contract', '1')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('Date');
});

test('a query string is refused: keys never travel in a URL', function () {
    $this->postJson('/api/v1/licence/activate?licenceKey='.self::KEY, $this->activateBody(), $this->tillHeaders())
        ->assertStatus(400)->assertJsonPath('code', 'request.invalid');
});

test('a till below the minimum version gets 426 app.update_required', function () {
    config(['licence.api.minimum_app_version' => '3.1.0']);

    $this->activateTill()->assertStatus(426)
        ->assertJsonPath('code', 'app.update_required')
        ->assertJsonPath('details.minimumAppVersion', '3.1.0');
    expect($this->licence->fresh()->device_id)->toBeNull();
});

test('a repeated Idempotency-Key gets the same status and body; the action runs once', function () {
    $headers = $this->tillHeaders(idempotencyKey: Ulid::new());
    $body = $this->activateBody();
    $first = $this->till('licence/activate', $body, $headers)->assertOk();

    $this->travel(5)->seconds();
    $second = $this->till('licence/activate', $body, $headers)->assertOk()->assertHeader('Idempotency-Replayed', 'true');

    expect($second->getContent())->toBe($first->getContent())
        ->and(AuditLog::query()->whereIn('action', ['licence.activated', 'licence.reinstalled'])->count())->toBe(1);
});

test('an error reply is replayed too, and a different body with the same key is 422', function () {
    $headers = $this->tillHeaders(idempotencyKey: '8f14e45f-ceea-467a-9575-3a1f8c3b9b1e');
    $this->till('licence/activate', $this->activateBody(self::OTHER_KEY), $headers)->assertNotFound();
    $this->till('licence/activate', $this->activateBody(self::OTHER_KEY), $headers)->assertNotFound()->assertHeader('Idempotency-Replayed', 'true');

    $this->till('licence/activate', $this->activateBody(), $headers)->assertStatus(422)->assertJsonPath('code', 'request.idempotency_mismatch');
});

test('a malformed Idempotency-Key is 400', function () {
    $this->till('licence/activate', $this->activateBody(), $this->tillHeaders(idempotencyKey: 'retry-1'))
        ->assertStatus(400)->assertJsonPath('details.field', 'Idempotency-Key');
});

test('validate: 60 an hour per install, then 429 rate.limited with Retry-After', function () {
    $token = $this->activateTill()->json('licenceToken');

    foreach (range(1, 60) as $i) {
        $this->validateTill($this->licence->id, $token)->assertOk();
    }

    $this->validateTill($this->licence->id, $token)->assertStatus(429)
        ->assertJsonPath('code', 'rate.limited')
        ->assertJsonPath('retryAfterSeconds', fn (int $seconds) => $seconds > 0 && $seconds <= 3600)
        ->assertHeader('Retry-After');

    $this->validateTill($this->licence->id, $token, self::OTHER_INSTALL)->assertNotFound();
});

test('activate: 10 an hour per IP', function () {
    foreach (range(1, 10) as $i) {
        $this->activateTill(install: sprintf('01K5T0Q8C40000000000000%03d', $i))->assertStatus($i === 1 ? 200 : 409);
    }

    $this->activateTill()->assertStatus(429)->assertJsonPath('code', 'rate.limited');
});
