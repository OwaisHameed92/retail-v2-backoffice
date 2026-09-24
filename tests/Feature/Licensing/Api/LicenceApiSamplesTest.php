<?php

use App\Domain\Licensing\Actions\RevokeLicence;
use App\Domain\Licensing\Actions\SuspendLicence;
use App\Domain\Licensing\Actions\UnsuspendLicence;
use App\Domain\Licensing\Signing\Base64Url;
use App\Domain\Licensing\Signing\Ed25519Jws;
use App\Domain\Licensing\Signing\Models\LicenceSigningKey;
use App\Domain\Tenancy\Actions\SuspendCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Uid\Ulid as SymfonyUlid;
use Tests\Feature\Licensing\Api\LicenceApiHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Samples for the EPOS team, made from real replies (like the till team's contract tests):
 * docs/specs/licence-api-samples/*.json.
 *
 * - `UPDATE_LICENCE_SAMPLES=1 php artisan test --filter=LicenceApiSamples` rewrites them.
 * - Otherwise this test fails when a reply's shape (keys and JSON types) drifts from the committed samples.
 *
 * Deterministic: fixed clock, ULIDs from a counter (01K5XTEST…), and the public RFC 8037 test key under the fake
 * kid `lk-test-01` (TEST ONLY, as in docs/specs/licence-token-verification.md).
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LicenceApiHelpers::class);

const LICENCE_SAMPLES_DIR = 'docs/specs/licence-api-samples';

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00:00', 'UTC'));

    $counter = 0;
    Str::createUlidsUsing(function () use (&$counter) {
        return new SymfonyUlid(sprintf('01K5XTEST%017d', ++$counter));
    });

    $pair = Ed25519Jws::keyPairFromSeed(Base64Url::decode('nWGxne_9WmC6hEr0kuwsxERJxWl7MmkZcDusAxyuf2A'));
    (new LicenceSigningKey)->forceFill([
        'kid' => 'lk-test-01',
        'public_key' => Base64Url::encode($pair['public']),
        'secret_key' => Base64Url::encode($pair['secret']),
        'is_active' => true,
    ])->save();
});

afterEach(function () {
    Str::createUlidsNormally();
});

/**
 * Where two JSON values differ in shape: object keys (and their order), lists by their first item, scalar types.
 * `null` matches any scalar type (a sample field that is null here may hold a value elsewhere).
 *
 * @return list<string>
 */
function licenceShapeDiff(mixed $actual, mixed $sample, string $path = '$'): array
{
    $type = fn (mixed $v): string => match (true) {
        is_array($v) && array_is_list($v) => 'list',
        is_array($v) => 'object',
        is_int($v), is_float($v) => 'number',
        default => get_debug_type($v),
    };

    [$a, $b] = [$type($actual), $type($sample)];

    if ($a === 'null' || $b === 'null') {
        return in_array('object', [$a, $b], true) || in_array('list', [$a, $b], true) ? ($a === $b ? [] : ["{$path}: {$a} vs sample {$b}"]) : [];
    }

    if ($a !== $b) {
        return ["{$path}: {$a} vs sample {$b}"];
    }

    if ($a === 'object') {
        if (array_keys($actual) !== array_keys($sample)) {
            return ["{$path}: keys [".implode(', ', array_keys($actual)).'] vs sample ['.implode(', ', array_keys($sample)).']'];
        }

        return array_merge([], ...array_map(fn ($key) => licenceShapeDiff($actual[$key], $sample[$key], "{$path}.{$key}"), array_keys($actual)));
    }

    if ($a === 'list' && $actual !== [] && $sample !== []) {
        return licenceShapeDiff($actual[0], $sample[0], "{$path}[0]");
    }

    return [];
}

/**
 * @param  array<string, mixed>  $data
 */
function licenceSample(string $name, array $data): void
{
    $path = base_path(LICENCE_SAMPLES_DIR."/{$name}.json");

    if (getenv('UPDATE_LICENCE_SAMPLES') === '1' || ! is_file($path)) {
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    $committed = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);

    expect(licenceShapeDiff($data, $committed))->toBe([], "The shape of {$name}.json drifted. If the change is intended, regenerate the samples with UPDATE_LICENCE_SAMPLES=1 and tell the EPOS team.");
}

function licenceSampleReply(string $name, TestResponse $response): TestResponse
{
    licenceSample($name, (array) $response->json());

    return $response;
}

test('the samples for the EPOS team match the real replies', function () {
    [$company, $licence] = $this->keyedTenant();

    // activate
    $activate = $this->activateBody();
    licenceSample('activate-request', $activate);
    $activated = licenceSampleReply('activate-reply', $this->till('activate', $activate)->assertOk());

    // check-in the next day, trading
    $this->travel(1)->days();
    $checkIn = $this->checkInBody(tokenId: $this->verifyToken($activated)->jti());
    $checkIn['requestedAt'] = '2026-09-25T09:00:00Z';
    licenceSample('check-in-request', $checkIn);
    licenceSampleReply('check-in-reply', $this->till('check-in', $checkIn)->assertOk()->assertJsonPath('licence.status', 'trial'));

    // check-in while suspended by staff: still 200, status in the signed token
    app(SuspendLicence::class)->handle($licence->refresh(), 'Unpaid invoice');
    licenceSampleReply('check-in-reply.suspended', $this->till('check-in', $checkIn)->assertOk()->assertJsonPath('licence.status', 'suspended'));
    app(UnsuspendLicence::class)->handle($licence->refresh());

    // check-in in the trial grace days
    $this->travelTo(CarbonImmutable::parse('2026-10-02 09:00:00', 'UTC'));
    licenceSampleReply('check-in-reply.grace', $this->till('check-in', $checkIn)->assertOk()->assertJsonPath('licence.status', 'grace'));
    $this->travelTo(CarbonImmutable::parse('2026-09-25 09:00:00', 'UTC'));

    // errors
    licenceSampleReply('error-request-invalid', $this->till('check-in', ['licenceKey' => self::KEY])->assertStatus(400));
    licenceSampleReply('error-licence-not-found', $this->till('activate', $this->activateBody(key: self::OTHER_KEY))->assertNotFound());
    licenceSampleReply('error-licence-bound-to-other-device', $this->till('activate', $this->activateBody(device: self::OTHER_PC, name: 'BACK-OFFICE'))->assertStatus(409));
    licenceSampleReply('error-licence-device-mismatch', $this->till('check-in', $this->checkInBody(device: self::OTHER_PC))->assertForbidden());
    licenceSampleReply('error-contract-unsupported', $this->till('check-in', $checkIn, ['X-SSPOS-Licence-Contract' => '2'])->assertStatus(409));

    // keys
    $keys = licenceSampleReply('keys-reply', $this->getJson('/api/v1/licence/keys', $this->tillHeaders())->assertOk());

    // deactivate
    $deactivate = ['licenceKey' => self::KEY, 'deviceId' => self::PC];
    licenceSample('deactivate-request', $deactivate);
    licenceSampleReply('deactivate-reply', $this->till('deactivate', $deactivate)->assertOk());

    // not activatable (company suspended), revoked
    app(SuspendCompany::class)->handle($company, 'Overdue');
    licenceSampleReply('error-licence-not-activatable', $this->till('activate', $activate)->assertForbidden());
    app(RevokeLicence::class)->handle($licence->refresh(), 'Refunded');
    licenceSampleReply('error-licence-revoked', $this->till('activate', $activate)->assertForbidden());

    // rate limited (another IP, so the flow above is not affected)
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
    foreach (range(1, 10) as $ignored) {
        $this->till('check-in', $this->checkInBody(key: self::OTHER_KEY));
    }
    licenceSampleReply('error-rate-limited', $this->till('check-in', $this->checkInBody(key: self::OTHER_KEY))->assertStatus(429));

    // The committed activate sample's token verifies with the committed JWKS sample, like on a till.
    $sampleReply = json_decode((string) file_get_contents(base_path(LICENCE_SAMPLES_DIR.'/activate-reply.json')), true);
    $sampleKeys = collect(json_decode((string) file_get_contents(base_path(LICENCE_SAMPLES_DIR.'/keys-reply.json')), true)['keys'])->keyBy('kid');
    $jws = Ed25519Jws::parse($sampleReply['token']);

    expect($jws['header']['kid'])->toBe('lk-test-01')
        ->and(Ed25519Jws::verify($jws['signingInput'], $jws['signature'], Base64Url::decode($sampleKeys['lk-test-01']['x'])))->toBeTrue()
        ->and($keys->json('keys.0.x'))->toBe('11qYAYKxCrfVS_7TyWQHOg7hcvPapiMlrwIaaPcHURo');
});

test('every sample file is produced by the test above', function () {
    $expected = [
        'activate-request', 'activate-reply', 'check-in-request', 'check-in-reply', 'check-in-reply.suspended', 'check-in-reply.grace',
        'deactivate-request', 'deactivate-reply', 'keys-reply',
        'error-request-invalid', 'error-licence-not-found', 'error-licence-bound-to-other-device', 'error-licence-device-mismatch',
        'error-licence-revoked', 'error-licence-not-activatable', 'error-contract-unsupported', 'error-rate-limited',
    ];

    $files = collect(glob(base_path(LICENCE_SAMPLES_DIR.'/*.json')) ?: [])->map(fn (string $path) => basename($path, '.json'))->sort()->values()->all();

    expect($files)->toEqualCanonicalizing($expected);
});
