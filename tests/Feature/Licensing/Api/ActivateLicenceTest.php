<?php

use App\Domain\Licensing\Actions\RenewLicence;
use App\Domain\Licensing\Actions\ResetDevice;
use App\Domain\Licensing\Actions\RevokeLicence;
use App\Domain\Licensing\Actions\SuspendLicence;
use App\Domain\Licensing\Data\RenewalTerm;
use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\LicenceAlert;
use App\Domain\Licensing\Signing\KeyStore;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Actions\SuspendCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
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

test('first activation binds the PC, starts the trial from the plan and replies with the till rows', function () {
    [$company, $licence] = $this->keyedTenant();

    $response = $this->till('activate', $this->activateBody())
        ->assertOk()
        ->assertHeader('X-Trace-Id')
        ->assertJsonPath('licence.id', $licence->id)
        ->assertJsonPath('licence.keyLast4', 'P8T5')
        ->assertJsonPath('licence.status', 'trial')
        ->assertJsonPath('licence.plan', 'standard')
        ->assertJsonPath('licence.features', ['stockControl', 'cashOffice'])
        ->assertJsonPath('licence.activatedAt', '2026-09-24T09:00:00Z')
        ->assertJsonPath('licence.trialEndsAt', '2026-10-01T09:00:00Z')
        ->assertJsonPath('licence.expiresAt', null)
        ->assertJsonPath('licence.graceDays', 3)
        ->assertJsonPath('licence.deviceId', self::PC)
        ->assertJsonPath('company.id', $company->id)
        ->assertJsonPath('company.companyId', $company->id)
        ->assertJsonPath('company.name', 'Khan Mini Mart')
        ->assertJsonPath('company.legalName', 'Khan Mini Mart Ltd')
        ->assertJsonPath('branch.code', 'LDS')
        ->assertJsonPath('branch.nation', 'england')
        ->assertJsonPath('register.id', $licence->register_id)
        ->assertJsonPath('register.code', '01')
        ->assertJsonPath('register.isMainTill', true)
        ->assertJsonPath('register.branchId', $licence->branch_id)
        ->assertJsonPath('sync', null)
        ->assertJsonPath('checkInEverySeconds', 86400)
        ->assertJsonPath('serverTimeUtc', '2026-09-24T09:00:00Z');

    expect($response->json())->toHaveKeys(['licence', 'token', 'company', 'branch', 'register', 'sync', 'checkInEverySeconds', 'serverTimeUtc'])
        ->and(array_key_exists('sync', $response->json()))->toBeTrue()
        ->and($response->json('register'))->not->toHaveKeys(['nextSaleNo', 'nextRefundNo'])
        ->and($response->json('branch'))->not->toHaveKey('nextPoNo');

    $licence->refresh();
    expect($licence->status)->toBe(LicenceStatus::Trial)
        ->and($licence->device_id)->toBe(self::PC)
        ->and($licence->device_name)->toBe('FRONT-TILL')
        ->and($licence->bound_at?->toIso8601String())->toBe('2026-09-24T09:00:00+00:00')
        ->and($licence->ends_at?->toIso8601String())->toBe('2026-10-01T09:00:00+00:00')
        ->and($licence->last_app_version)->toBe('1.4.2')
        ->and($licence->last_ip)->toBe('127.0.0.1')
        ->and($licence->last_check_in_at)->not->toBeNull();

    // The company's 7-day trial starts on its first till activation.
    expect(Company::query()->find($company->id)->trial_ends_at?->toIso8601String())->toBe('2026-10-01T09:00:00+00:00');

    $audit = AuditLog::query()->where('action', 'licence.activated')->sole();
    expect($audit->actor_type)->toBe((new Register)->getMorphClass())
        ->and($audit->actor_id)->toBe($licence->register_id)
        ->and($audit->after['device_id'])->toBe(self::PC)
        ->and($audit->meta['company_trial_ends_at'])->toBe('2026-10-01T09:00:00+00:00');
});

test('the audit actor is the till even when an admin session shares the request', function () {
    [, $licence] = $this->keyedTenant();

    $this->actingAs($this->admin(), 'admin');
    $this->till('activate', $this->activateBody())->assertOk();

    expect(AuditLog::query()->where('action', 'licence.activated')->sole()->actor_id)->toBe($licence->register_id);
});

test('a licence renewed before activation starts active with the paid grace', function () {
    [, $licence] = $this->keyedTenant();
    app(RenewLicence::class)->handle($licence, RenewalTerm::year(), notify: false);

    $this->till('activate', $this->activateBody())
        ->assertOk()
        ->assertJsonPath('licence.status', 'active')
        ->assertJsonPath('licence.trialEndsAt', null)
        ->assertJsonPath('licence.expiresAt', '2027-09-24T22:59:59Z')
        ->assertJsonPath('licence.graceDays', 7);

    expect($licence->refresh()->status)->toBe(LicenceStatus::Active)
        ->and($licence->activated_at)->not->toBeNull();
});

test('the reply token verifies and carries the spec claims', function () {
    [$company, $licence] = $this->keyedTenant();

    $response = $this->till('activate', $this->activateBody())->assertOk();
    $token = $this->verifyToken($response);

    expect($token->header())->toBe(['alg' => 'EdDSA', 'kid' => app(KeyStore::class)->active()->kid, 'typ' => 'sspos-licence+jwt'])
        ->and(array_keys($token->claims()))->toBe(['iss', 'iat', 'jti', 'lic', 'keyLast4', 'companyId', 'branchId', 'registerId', 'deviceId', 'status', 'plan', 'features', 'expiresAt', 'graceDays', 'validUntil'])
        ->and($token->claims())->toMatchArray([
            'iss' => 'sspos-portal',
            'iat' => CarbonImmutable::parse('2026-09-24 09:00:00', 'UTC')->getTimestamp(),
            'lic' => $licence->id,
            'keyLast4' => 'P8T5',
            'companyId' => $company->id,
            'branchId' => $licence->branch_id,
            'registerId' => $licence->register_id,
            'deviceId' => self::PC,
            'status' => 'trial',
            'plan' => 'standard',
            'features' => ['stockControl', 'cashOffice'],
            // Trial end, then 3 trial grace days: min(iat + 14 days, 1 Oct + 3 days).
            'expiresAt' => '2026-10-01T09:00:00Z',
            'graceDays' => 3,
            'validUntil' => '2026-10-04T09:00:00Z',
        ])
        ->and($token->jti())->toMatch('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/');
});

test('validUntil is capped at 14 days offline for a long paid licence', function () {
    [, $licence] = $this->keyedTenant();
    app(RenewLicence::class)->handle($licence, RenewalTerm::year(), notify: false);

    $token = $this->verifyToken($this->till('activate', $this->activateBody())->assertOk());

    expect($token->claim('validUntil'))->toBe('2026-10-08T09:00:00Z')
        ->and($token->claim('expiresAt'))->toBe('2027-09-24T22:59:59Z');
});

test('activating again on the bound PC (reinstall) gives the same reply and keeps the trial dates', function () {
    [, $licence] = $this->keyedTenant();
    $first = $this->till('activate', $this->activateBody())->assertOk()->json();

    $this->travel(2)->days();
    $second = $this->till('activate', $this->activateBody(name: 'FRONT-TILL-NEW'))->assertOk()->json();

    expect(array_keys($second))->toBe(array_keys($first))
        ->and($second['licence']['activatedAt'])->toBe($first['licence']['activatedAt'])
        ->and($second['licence']['trialEndsAt'])->toBe('2026-10-01T09:00:00Z')
        ->and($second['company'])->toEqual($first['company'])
        ->and($licence->refresh()->device_name)->toBe('FRONT-TILL-NEW')
        ->and(AuditLog::query()->where('action', 'licence.reinstalled')->count())->toBe(1);
});

test('another PC gets 409 bound_to_other_device and raises a sameKeyTwoDevices alert', function () {
    [, $licence] = $this->keyedTenant();
    $this->till('activate', $this->activateBody())->assertOk();

    $this->till('activate', $this->activateBody(device: self::OTHER_PC, name: 'BACK-OFFICE'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'licence.bound_to_other_device')
        ->assertJsonStructure(['code', 'message', 'traceId', 'retryAfterSeconds', 'rejectedKey']);
    $this->till('activate', $this->activateBody(device: self::OTHER_PC, name: 'BACK-OFFICE'))->assertStatus(409);

    $alert = LicenceAlert::withoutCompanyScope()->sole();
    expect($alert->type)->toBe(LicenceAlertType::SameKeyTwoDevices)
        ->and($alert->count)->toBe(2)
        ->and($alert->company_id)->toBe($licence->company_id)
        ->and($alert->details['deviceName'])->toBe('BACK-OFFICE')
        ->and($alert->details['boundDeviceName'])->toBe('FRONT-TILL')
        ->and(json_encode($alert->details))->not->toContain(self::OTHER_PC)
        ->and($licence->refresh()->device_id)->toBe(self::PC);
});

test('after "Reset PC" a new PC can activate; the trial is not restarted', function () {
    [, $licence] = $this->keyedTenant();
    $this->till('activate', $this->activateBody())->assertOk();
    $this->travel(3)->days();

    app(ResetDevice::class)->handle($licence->refresh());

    $this->till('activate', $this->activateBody(device: self::OTHER_PC, name: 'NEW-PC'))
        ->assertOk()
        ->assertJsonPath('licence.deviceId', self::OTHER_PC)
        ->assertJsonPath('licence.activatedAt', '2026-09-24T09:00:00Z')
        ->assertJsonPath('licence.trialEndsAt', '2026-10-01T09:00:00Z');

    expect(AuditLog::query()->where('action', 'licence.device_bound')->count())->toBe(1)
        ->and($licence->refresh()->bound_at?->toIso8601String())->toBe('2026-09-27T09:00:00+00:00');
});

test('revoked licences cannot be activated', function () {
    [, $licence] = $this->keyedTenant();
    app(RevokeLicence::class)->handle($licence, 'Refunded');

    $this->till('activate', $this->activateBody())->assertForbidden()->assertJsonPath('code', 'licence.revoked');
});

test('suspended or expired licences are not activatable', function (Closure $setup) {
    [$company, $licence] = $this->keyedTenant();
    $setup($this, $company, $licence);

    $this->till('activate', $this->activateBody())
        ->assertForbidden()
        ->assertJsonPath('code', 'licence.not_activatable');

    expect($licence->refresh()->device_id)->toBeNull();
})->with([
    'suspended by staff' => [fn ($test, $company, $licence) => app(SuspendLicence::class)->handle($licence, 'Unpaid invoice')],
    'company suspended' => [fn ($test, $company, $licence) => app(SuspendCompany::class)->handle($company, 'Unpaid')],
    'expired after a reset' => [function ($test, $company, $licence) {
        $test->activate($licence, CarbonImmutable::now()->subDays(30));
        app(ResetDevice::class)->handle($licence->refresh());
    }],
]);

test('an unknown key and a mistyped key both get 404 licence.not_found', function (string $key) {
    $this->keyedTenant();

    $this->till('activate', $this->activateBody(key: $key))
        ->assertNotFound()
        ->assertJsonPath('code', 'licence.not_found')
        ->assertJsonPath('rejectedKey', null);
})->with([
    'unknown' => ['SSP-4HWC-J6ZB-81ME-QV5H'],
    'bad check character' => ['SSP-7K2Q-9DMF-3XRA-P8T6'],
    'too short' => ['SSP-7K2Q'],
]);

test('keys are normalised: lower case with spaces finds the licence', function () {
    [, $licence] = $this->keyedTenant();

    $this->till('activate', $this->activateBody(key: 'ssp 7k2q 9dmf 3xra p8t5'))->assertOk()->assertJsonPath('licence.id', $licence->id);
});

test('missing or wrong fields are 400 request.invalid', function (array $body) {
    $this->keyedTenant();

    $this->till('activate', $body)->assertStatus(400)->assertJsonPath('code', 'request.invalid');
})->with([
    'no key' => [['deviceId' => 'PC-1']],
    'no device' => [['licenceKey' => 'SSP-7K2Q-9DMF-3XRA-P8T5']],
    'device id too long' => [['licenceKey' => 'SSP-7K2Q-9DMF-3XRA-P8T5', 'deviceId' => str_repeat('x', 192)]],
    'key not a string' => [['licenceKey' => 123, 'deviceId' => 'PC-1']],
]);
