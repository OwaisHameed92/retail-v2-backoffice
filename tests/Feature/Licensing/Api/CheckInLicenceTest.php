<?php

use App\Domain\Licensing\Actions\RenewLicence;
use App\Domain\Licensing\Actions\ResetDevice;
use App\Domain\Licensing\Actions\RevokeLicence;
use App\Domain\Licensing\Actions\SuspendLicence;
use App\Domain\Licensing\Data\RenewalTerm;
use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Models\LicenceAlert;
use App\Domain\Licensing\Models\LicenceDevice;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Actions\CancelCompany;
use App\Domain\Tenancy\Actions\DeactivateRegister;
use App\Domain\Tenancy\Actions\SuspendCompany;
use App\Domain\Tenancy\Models\Branch;
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

/** Activates KEY on PC through the API, returns the licence. */
function activatedLicence($test): Licence
{
    [, $licence] = $test->keyedTenant();
    $test->till('activate', $test->activateBody())->assertOk();

    return $licence->refresh();
}

test('check-in returns the licence, a fresh token and records the contact', function () {
    $licence = activatedLicence($this);
    $this->travel(1)->days();

    $response = $this->till('check-in', $this->checkInBody(tokenId: '01K5XTEST00000000000000001'), ['X-SSPOS-Licence-Contract' => '1', 'X-SSPOS-App-Version' => '1.5.0'])
        ->assertOk()
        ->assertJsonPath('licence.id', $licence->id)
        ->assertJsonPath('licence.status', 'trial')
        ->assertJsonPath('message', 'Free trial until 1 October 2026.')
        ->assertJsonPath('checkInEverySeconds', 86400)
        ->assertJsonPath('serverTimeUtc', '2026-09-25T09:00:00Z');

    expect(array_keys($response->json()))->toBe(['licence', 'token', 'message', 'checkInEverySeconds', 'serverTimeUtc'])
        ->and($this->verifyToken($response)->claim('iat'))->toBe(CarbonImmutable::parse('2026-09-25 09:00:00', 'UTC')->getTimestamp());

    $licence->refresh();
    expect($licence->last_check_in_at?->toIso8601String())->toBe('2026-09-25T09:00:00+00:00')
        ->and($licence->last_app_version)->toBe('1.5.0')
        ->and($licence->last_ip)->toBe('127.0.0.1');

    // Daily check-ins are not audited; an app update is, with the till as actor.
    $update = AuditLog::query()->where('action', 'licence.app_updated')->sole();
    expect($update->before)->toBe(['last_app_version' => '1.4.2'])
        ->and($update->after)->toBe(['last_app_version' => '1.5.0'])
        ->and($update->actor_type)->toBe((new Register)->getMorphClass());

    $this->till('check-in', $this->checkInBody(), ['X-SSPOS-Licence-Contract' => '1', 'X-SSPOS-App-Version' => '1.5.0'])->assertOk();
    expect(AuditLog::query()->where('action', 'licence.app_updated')->count())->toBe(1)
        ->and(LicenceDevice::withoutCompanyScope()->sole()->times_seen)->toBe(3);
});

test('check-in reports every status with a signed token', function (Closure $setup, string $status, ?string $message) {
    $licence = activatedLicence($this);
    $setup($this, $licence);

    $response = $this->till('check-in', $this->checkInBody())
        ->assertOk()
        ->assertJsonPath('licence.status', $status);

    $token = $this->verifyToken($response);
    expect($token->claim('status'))->toBe($status)
        ->and($token->claim('deviceId'))->toBe(self::PC);

    if ($message === null) {
        expect($response->json('message'))->toBeNull();
    } else {
        expect($response->json('message'))->toContain($message);
    }
})->with([
    'trial' => [fn () => null, 'trial', 'Free trial until 1 October 2026.'],
    'active (renewed)' => [fn ($test, $licence) => app(RenewLicence::class)->handle($licence, RenewalTerm::year(), notify: false), 'active', null],
    'grace after the trial' => [fn ($test) => $test->travelTo(CarbonImmutable::parse('2026-10-02 09:00:00', 'UTC')), 'grace', 'The till will stop taking sales on 4 October 2026'],
    'expired after the trial grace' => [fn ($test) => $test->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC')), 'expired', 'The free trial has ended.'],
    'suspended by staff' => [fn ($test, $licence) => app(SuspendLicence::class)->handle($licence, 'Unpaid invoice'), 'suspended', 'This licence is suspended. Unpaid invoice.'],
    'company suspended' => [fn ($test, $licence) => app(SuspendCompany::class)->handle(Company::query()->find($licence->company_id), 'Overdue'), 'suspended', 'The business account is suspended.'],
    'company cancelled' => [fn ($test, $licence) => app(CancelCompany::class)->handle(Company::query()->find($licence->company_id), 'Closed'), 'suspended', 'The business account is closed.'],
    'branch deactivated' => [fn ($test, $licence) => Branch::withoutCompanyScope()->whereKey($licence->branch_id)->update(['is_active' => false]), 'suspended', 'The branch is deactivated.'],
    'till deactivated' => [fn ($test, $licence) => app(DeactivateRegister::class)->handle(Register::withoutCompanyScope()->findOrFail($licence->register_id)), 'suspended', 'Till deactivated'],
    'revoked' => [fn ($test, $licence) => app(RevokeLicence::class)->handle($licence, 'Refunded'), 'revoked', 'This licence has been revoked.'],
]);

test('a grace or expired token is only valid until the grace ends', function () {
    activatedLicence($this);
    $this->travelTo(CarbonImmutable::parse('2026-10-02 09:00:00', 'UTC'));

    $token = $this->verifyToken($this->till('check-in', $this->checkInBody())->assertOk());

    expect($token->claim('expiresAt'))->toBe('2026-10-01T09:00:00Z')
        ->and($token->claim('graceDays'))->toBe(3)
        ->and($token->claim('validUntil'))->toBe('2026-10-04T09:00:00Z');
});

test('check-in from another PC is 403 device_mismatch and raises a deviceMismatch alert', function () {
    $licence = activatedLicence($this);

    $this->till('check-in', $this->checkInBody(device: self::OTHER_PC))
        ->assertForbidden()
        ->assertJsonPath('code', 'licence.device_mismatch');

    $alert = LicenceAlert::withoutCompanyScope()->sole();
    expect($alert->type)->toBe(LicenceAlertType::DeviceMismatch)
        ->and($alert->licence_id)->toBe($licence->id)
        ->and($alert->details['deviceIdEnding'])->toBe('…BACK')
        ->and($licence->refresh()->last_check_in_at?->toIso8601String())->toBe('2026-09-24T09:00:00+00:00');
});

test('after "Reset PC" the old PC gets 403 device_mismatch without an alert', function () {
    $licence = activatedLicence($this);
    app(ResetDevice::class)->handle($licence);

    $this->till('check-in', $this->checkInBody())
        ->assertForbidden()
        ->assertJsonPath('code', 'licence.device_mismatch')
        ->assertJsonPath('message', 'This till needs to be activated again. Enter the licence key to activate it.');

    expect(LicenceAlert::withoutCompanyScope()->count())->toBe(0);
});

test('check-in with an unknown key is 404', function () {
    activatedLicence($this);

    $this->till('check-in', $this->checkInBody(key: self::OTHER_KEY))->assertNotFound()->assertJsonPath('code', 'licence.not_found');
});
