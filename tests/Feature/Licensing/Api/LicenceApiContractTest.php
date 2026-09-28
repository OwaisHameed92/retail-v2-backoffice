<?php

use App\Domain\Licensing\Actions\ReleaseDevice;
use App\Domain\Licensing\Actions\SuspendLicence;
use App\Domain\Licensing\Signing\Sspos\SsposTokenVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\Api\LicenceApiHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;
use Tests\Support\SsposDocs;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LicenceApiHelpers::class);

/*
 * Contract tests (v1.3.1 §17.7, §17.15): every sample of the three endpoints validates against its schema, the
 * samples replay against the API, and our replies (success and error) validate against the reply schemas.
 */

/** Sample file → schema, for licence/activate, licence/validate, devices/deactivate and the errors. */
function licenceSampleSchema(string $file): ?string
{
    return match (true) {
        str_starts_with($file, 'licence-activate-request') => 'licence-activate-request.schema.json',
        str_starts_with($file, 'licence-activate-reply') => 'licence-activate-reply.schema.json',
        str_starts_with($file, 'validate-request') => 'validate-request.schema.json',
        str_starts_with($file, 'validate-reply') => 'validate-reply.schema.json',
        str_starts_with($file, 'deactivate-request') => 'deactivate-request.schema.json',
        str_starts_with($file, 'deactivate-reply') => 'deactivate-reply.schema.json',
        str_starts_with($file, 'error.') => 'error-reply.schema.json',
        default => null,
    };
}

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
    $this->withSigningKey();
});

test('every sample of these endpoints validates against its schema', function () {
    $checked = 0;

    foreach (glob(SsposDocs::dir().'/samples/*.json') ?: [] as $path) {
        $schema = licenceSampleSchema(basename($path));

        if ($schema === null) {
            continue;
        }

        $data = json_decode((string) file_get_contents($path), false, 512, JSON_THROW_ON_ERROR);
        expect($this->schemas()->validate($data, $schema))->toBe([], basename($path));
        $checked++;
    }

    expect($checked)->toBeGreaterThanOrEqual(25);
});

test('the sample activate reply token verifies with our verifier', function () {
    $token = SsposDocs::sample('licence-activate-reply.json')['licenceToken'];

    expect(app(SsposTokenVerifier::class)->verify($token)->licenceId())->toBe('01K5T0Q8C4000000000000Y101');
});

test('the request samples replay against the API and every reply validates', function () {
    [, $licence] = $this->keyedTenant();

    $activate = SsposDocs::sample('licence-activate-request.json');
    $activate['licenceKey'] = self::KEY;
    $reply = $this->till('licence/activate', $activate, $this->tillHeaders($activate['installId']))->assertOk();
    expect($this->schemaErrors($reply, 'licence-activate-reply.schema.json'))->toBe([]);

    $validate = SsposDocs::sample('validate-request.per-till.json');
    $validate['licenceId'] = $licence->id;
    $validate['tokenSha256'] = hash('sha256', (string) $reply->json('licenceToken'));
    $reply = $this->till('licence/validate', $validate, $this->tillHeaders($validate['installId']))->assertOk()->assertJsonPath('licenceToken', null);
    expect($this->schemaErrors($reply, 'validate-reply.schema.json'))->toBe([]);

    $deactivate = SsposDocs::sample('deactivate-request.json');
    $deactivate['registerId'] = self::TILL_REGISTER;
    $deactivate['installId'] = $activate['installId'];
    $reply = $this->till('devices/deactivate', $deactivate, $this->tillHeaders($activate['installId']))->assertOk();
    expect($this->schemaErrors($reply, 'deactivate-reply.schema.json'))->toBe([]);

    $reply = $this->till('licence/validate', $validate, $this->tillHeaders($validate['installId']))->assertOk()->assertJsonPath('status', 'released');
    expect($this->schemaErrors($reply, 'validate-reply.schema.json'))->toBe([]);
});

test('validate replies for every status validate against the schema', function () {
    [$company, $licence] = $this->keyedTenant();
    $token = $this->activateTill()->json('licenceToken');
    $replies = [];

    $replies['new token'] = $this->validateTill($licence->id, 'SSPOS1.other.token');
    $this->travelTo(CarbonImmutable::parse('2026-10-13 09:00:00', 'UTC'));
    $replies['grace'] = $this->validateTill($licence->id, $token);
    $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00', 'UTC'));
    $replies['expired'] = $this->validateTill($licence->id, $token);
    app(SuspendLicence::class)->handle($licence->fresh(), 'Check');
    $replies['suspended'] = $this->validateTill($licence->id, $token);
    app(ReleaseDevice::class)->handle($licence->fresh());
    $replies['released'] = $this->validateTill($licence->id, $token);

    foreach ($replies as $name => $reply) {
        $reply->assertOk();
        expect($this->schemaErrors($reply, 'validate-reply.schema.json'))->toBe([], $name);
    }
});

test('our error replies validate against error-reply.schema.json', function () {
    [$company, $licence] = $this->keyedTenant();
    $this->activateTill()->assertOk();
    config(['licence.api.rate_limits.activate_per_ip_per_hour' => 100]);

    $errors = [
        'already used' => $this->activateTill(install: self::OTHER_INSTALL, code: self::OTHER_CODE)->assertStatus(409),
        'not found' => $this->activateTill(self::OTHER_KEY, self::OTHER_INSTALL)->assertNotFound(),
        'invalid' => $this->till('licence/activate', ['installId' => self::INSTALL])->assertStatus(400),
        'contract' => $this->postJson('/api/v1/licence/validate', [], ['X-SSPOS-Contract' => '9'])->assertStatus(409),
        'device' => $this->deactivateTill('01K5T0Q8C4000000000000R999')->assertNotFound(),
    ];

    foreach (range(1, 4) as $i) {
        $this->activateTill(self::OTHER_KEY, self::OTHER_INSTALL);
    }
    $errors['too many'] = $this->activateTill(self::OTHER_KEY, self::OTHER_INSTALL)->assertStatus(429);

    config(['licence.api.minimum_app_version' => '9.0.0']);
    $errors['update'] = $this->activateTill()->assertStatus(426);

    foreach ($errors as $name => $reply) {
        expect($this->schemaErrors($reply, 'error-reply.schema.json'))->toBe([], $name);
    }
});
