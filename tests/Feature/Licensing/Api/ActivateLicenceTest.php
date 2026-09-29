<?php

use App\Domain\Licensing\Actions\ReissueKey;
use App\Domain\Licensing\Actions\RevokeLicence;
use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Models\LicenceAlert;
use App\Domain\Licensing\Models\LicenceDevice;
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
});

test('first activation binds the install, starts the trial and answers with a till token', function () {
    [$company, $licence] = $this->keyedTenant();

    $response = $this->activateTill()->assertOk()
        ->assertHeader('X-SSPOS-Contract', '1')
        // v1.4.1: expiresAt is the trial's real end (no grace days), 7 days away → the till's 7-day warning.
        ->assertJsonPath('status', 'expiring')
        ->assertJsonPath('licence.licenceId', $licence->id)
        ->assertJsonPath('licence.kind', 'trial')
        ->assertJsonPath('licence.companyId', $company->id)
        ->assertJsonPath('licence.installCode', self::INSTALL_CODE)
        ->assertJsonPath('licence.maxRegisters', 2)
        ->assertJsonPath('licence.expiresAt', '2026-10-12T09:00:00Z')
        ->assertJsonPath('portalTimeUtc', '2026-10-05T09:00:00Z')
        ->assertJsonPath('nextCheckAfterSeconds', 86400)
        ->assertJsonPath('messages.0.title', 'Trial ends soon');

    $licence->refresh();
    expect($licence->device_id)->toBe(self::INSTALL)
        ->and($licence->install_code)->toBe(self::INSTALL_CODE)
        ->and($licence->device_name)->toBe('TILL-1')
        ->and($licence->existing_ids)->toBe(['companyId' => self::TILL_COMPANY, 'branchId' => self::TILL_BRANCH, 'registerId' => self::TILL_REGISTER])
        ->and($licence->os)->toBe(['name' => 'Windows', 'version' => '10.0.26200', 'architecture' => 'x64'])
        ->and($licence->till_clock_skew_seconds)->toBe(90)
        ->and($licence->status)->toBe(LicenceStatus::Trial)
        ->and($licence->trial_ends_at?->toIso8601String())->toBe('2026-10-12T09:00:00+00:00')
        ->and($licence->token_sha256)->toBe(hash('sha256', (string) $response->json('licenceToken')))
        ->and($company->fresh()->trial_ends_at?->toIso8601String())->toBe('2026-10-12T09:00:00+00:00')
        ->and(AuditLog::query()->where('action', 'licence.activated')->count())->toBe(1)
        ->and(LicenceDevice::withoutCompanyScope()->sole()->last_outcome)->toBe('activated');
});

test('the token verifies like the till and carries the shop, install code and mapped features', function () {
    [$company, $licence] = $this->keyedTenant();
    $company->forceFill(['address' => '14 Kirkgate', 'email' => 'shop@khan.test', 'contact_name' => 'Imran Khan'])->save();

    $token = $this->verifyToken($this->activateTill()->assertOk());
    $branch = $this->branchOf($company);

    expect($token->kid())->toBe(SsposDocs::PORTAL_KID)
        ->and($token->signerCertificate)->not->toBeNull()
        ->and($token->payload)->toMatchArray([
            'licenceId' => $licence->id,
            'kind' => 'trial',
            'source' => 'portal',
            'companyId' => $company->id,
            'branchId' => $branch->id,
            'businessName' => 'Khan Mini Mart',
            'branchName' => $branch->name,
            'installCode' => self::INSTALL_CODE,
            'maxRegisters' => 2,
            'validFrom' => '2026-10-05T09:00:00Z',
            'expiresAt' => '2026-10-12T09:00:00Z',
            'onlineCheck' => ['required' => true, 'intervalHours' => 24, 'graceDays' => 14],
        ])
        // Module 1.11: the owner's name defaults from the owner login given when the tenant was created.
        ->and($token->payload['company'])->toMatchArray(['address' => $branch->address ?? '14 Kirkgate', 'email' => 'shop@khan.test', 'ownerName' => 'Aisha Khan'])
        ->and($token->payload['features'] ?? [])->each->toMatch('/^[a-z0-9]+(_[a-z0-9]+)*$/');
});

test('features are the till\'s names, the company\'s multi-branch adds multi_branch and limits.branches', function () {
    [$company, $licence] = $this->keyedTenant();
    $licence->forceFill(['features' => ['loyalty', 'second_screen', 'promotions']])->save();
    $company->forceFill(['multi_branch' => true, 'max_branches' => 3])->save();

    $this->activateTill()->assertOk()
        ->assertJsonPath('licence.features', ['loyalty', 'promotions', 'multi_branch', 'second_screen'])
        ->assertJsonPath('licence.limits', ['branches' => 3]);
});

test('the same install activating again gets 200 and stays bound (retry or reinstall)', function () {
    [, $licence] = $this->keyedTenant();
    $this->activateTill()->assertOk();

    $this->activateTill()->assertOk()->assertJsonPath('status', 'expiring');

    expect($licence->fresh()->device_id)->toBe(self::INSTALL)
        ->and(AuditLog::query()->where('action', 'licence.reinstalled')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'licence.activated')->count())->toBe(1);
});

test('another install gets 409 key.already_used with the bound PC and a sameKeyTwoDevices alert', function () {
    [, $licence] = $this->keyedTenant();
    $this->activateTill()->assertOk();

    $this->activateTill(install: self::OTHER_INSTALL, code: self::OTHER_CODE)->assertStatus(409)
        ->assertJsonPath('code', 'key.already_used')
        ->assertJsonPath('details', ['deviceName' => 'TILL-1', 'installCode' => self::INSTALL_CODE, 'activatedAtUtc' => '2026-10-05T09:00:00Z'])
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'TILL-1'));

    expect($licence->fresh()->device_id)->toBe(self::INSTALL)
        ->and(LicenceAlert::withoutCompanyScope()->sole()->type)->toBe(LicenceAlertType::SameKeyTwoDevices);
});

test('a key past the branch\'s tills allowed gets 403 licence.seat_limit', function () {
    [$company, $licence] = $this->keyedTenant();
    $branch = $this->branchOf($company);
    $second = $this->licenceOf($this->registerOf($branch, '02'));
    $second->forceFill(['device_id' => self::OTHER_INSTALL, 'bound_at' => now(), 'activated_at' => now(), 'status' => LicenceStatus::Trial, 'trial_ends_at' => now()->addDays(7)])->save();
    $this->allowTills($branch, 1);

    $this->activateTill()->assertForbidden()
        ->assertJsonPath('code', 'licence.seat_limit')
        ->assertJsonPath('details', ['maxRegisters' => 1, 'registersInUse' => 1]);

    expect($licence->fresh()->device_id)->toBeNull();
});

test('a replaced key or one withdrawn before use is 410 key.expired', function () {
    [, $licence] = $this->keyedTenant();
    $this->activateTill()->assertOk();
    app(ReissueKey::class)->handle($licence->fresh());

    $this->activateTill()->assertStatus(410)->assertJsonPath('code', 'key.expired');
    expect(LicenceAlert::withoutCompanyScope()->where('type', LicenceAlertType::ReissuedKeyUsed->value)->count())->toBe(1);

    [, $unused] = $this->keyedTenant('Corner Shop', 1, 'CRN', self::OTHER_KEY);
    app(RevokeLicence::class)->handle($unused, 'Sold');
    $this->activateTill(self::OTHER_KEY, self::OTHER_INSTALL, self::OTHER_CODE)->assertStatus(410)->assertJsonPath('code', 'key.expired');
});

test('a suspended account or a licence revoked after use is 403 licence.not_active', function () {
    [$company, $licence] = $this->keyedTenant();
    app(SuspendCompany::class)->handle($company, 'Unpaid');

    $this->activateTill()->assertForbidden()->assertJsonPath('code', 'licence.not_active')->assertJsonPath('details.status', 'suspended');
    expect($licence->fresh()->device_id)->toBeNull();

    [, $used] = $this->keyedTenant('Corner Shop', 1, 'CRN', self::OTHER_KEY);
    $this->activateTill(self::OTHER_KEY, self::OTHER_INSTALL, self::OTHER_CODE)->assertOk();
    app(RevokeLicence::class)->handle($used->fresh(), 'Fraud');
    $this->activateTill(self::OTHER_KEY, self::OTHER_INSTALL, self::OTHER_CODE)->assertForbidden()->assertJsonPath('details.status', 'revoked');
});

test('unknown and malformed keys are 404 key.not_found', function (string $key) {
    $this->keyedTenant();

    $this->activateTill($key)->assertNotFound()->assertJsonPath('code', 'key.not_found')->assertJsonMissingPath('details.field');
})->with(['unknown' => ['SSP-4HWC-J6ZB-81ME-QV5H'], 'typo' => ['SSP-7K2Q-9DMF-3XRA-P8T6'], 'not a key' => ['HELLO-WORLD']]);

test('keys are normalised: lower case with spaces finds the licence', function () {
    [, $licence] = $this->keyedTenant();

    $this->activateTill(' ssp 7k2q 9dmf 3xra p8t5 ')->assertOk();
    expect($licence->fresh()->device_id)->toBe(self::INSTALL);
});

test('5 wrong keys per install in 15 minutes, then 429 activation.too_many_attempts', function () {
    [, $licence] = $this->keyedTenant();
    config(['licence.api.rate_limits.activate_per_ip_per_hour' => 100]);

    foreach (range(1, 5) as $i) {
        $this->activateTill(self::OTHER_KEY)->assertNotFound();
    }

    $this->activateTill()->assertStatus(429)
        ->assertJsonPath('code', 'activation.too_many_attempts')
        ->assertJsonPath('retryAfterSeconds', 900)
        ->assertHeader('Retry-After', '900');
    expect($licence->fresh()->device_id)->toBeNull();

    // Another install is not blocked, and the window passes.
    $this->activateTill(install: self::OTHER_INSTALL, code: self::OTHER_CODE)->assertOk();
});

test('missing or malformed fields are 400 request.invalid naming the field', function (string $field, mixed $value) {
    $this->keyedTenant();
    $body = $this->activateBody();
    data_set($body, $field, $value);

    $this->till('licence/activate', $body)->assertStatus(400)
        ->assertJsonPath('code', 'request.invalid')
        ->assertJsonPath('details.field', $field);
})->with([
    'install id' => ['installId', 'not-a-ulid'],
    'install code' => ['installCode', 'AC4F3FHG'],
    'existing ids' => ['existingIds.registerId', null],
    'app version' => ['appVersion', 'three'],
    'clock' => ['tillClockUtc', 'yesterday-ish'],
]);

test('the install id header must match the body', function () {
    $this->keyedTenant();

    $this->till('licence/activate', $this->activateBody(), $this->tillHeaders(self::OTHER_INSTALL))
        ->assertStatus(400)->assertJsonPath('details.field', 'installId');
    expect(Licence::withoutCompanyScope()->whereNotNull('device_id')->count())->toBe(0);
});
