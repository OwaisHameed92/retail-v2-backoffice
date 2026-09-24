<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Licensing\Actions\ReissueKey;
use App\Domain\Licensing\Actions\RevokeLicence;
use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Models\LicenceAlert;
use App\Domain\Licensing\Models\RetiredLicenceKey;
use App\Domain\Shared\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Licensing\Api\LicenceApiHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LicenceApiHelpers::class);

beforeEach(function () {
    Mail::fake();
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-09-24 09:00:00', 'UTC'));
    $this->withSigningKey();
});

test('deactivate releases the key from the bound PC so another PC can activate', function () {
    [, $licence] = $this->keyedTenant();
    $this->till('activate', $this->activateBody())->assertOk();

    $this->till('deactivate', ['licenceKey' => self::KEY, 'deviceId' => self::PC])->assertOk()->assertExactJson(['released' => true]);

    $licence->refresh();
    expect($licence->device_id)->toBeNull()
        ->and($licence->bound_at)->toBeNull()
        ->and($licence->activated_at)->not->toBeNull();

    $audit = AuditLog::query()->where('action', 'licence.released')->sole();
    expect($audit->actor_id)->toBe($licence->register_id)
        ->and($audit->before['device_id'])->toBe(self::PC);

    // Idempotent, then the key moves to a new PC with the same trial.
    $this->till('deactivate', ['licenceKey' => self::KEY, 'deviceId' => self::PC])->assertOk()->assertExactJson(['released' => true]);
    $this->till('activate', $this->activateBody(device: self::OTHER_PC))->assertOk()->assertJsonPath('licence.trialEndsAt', '2026-10-01T09:00:00Z');
});

test('only the bound PC can deactivate; revoked licences answer licence.revoked', function () {
    [, $licence] = $this->keyedTenant();
    $this->till('activate', $this->activateBody())->assertOk();

    $this->till('deactivate', ['licenceKey' => self::KEY, 'deviceId' => self::OTHER_PC])->assertForbidden()->assertJsonPath('code', 'licence.device_mismatch');
    expect($licence->refresh()->device_id)->toBe(self::PC)
        ->and(LicenceAlert::withoutCompanyScope()->sole()->type)->toBe(LicenceAlertType::DeviceMismatch);

    app(RevokeLicence::class)->handle($licence, 'Refunded');
    $this->till('deactivate', ['licenceKey' => self::KEY, 'deviceId' => self::PC])->assertForbidden()->assertJsonPath('code', 'licence.revoked');
    $this->till('deactivate', ['licenceKey' => self::OTHER_KEY, 'deviceId' => self::PC])->assertNotFound();
});

test('a reissued key is not found, and the old PC using it raises a reissuedKeyUsed alert', function () {
    [, $licence] = $this->keyedTenant();
    $this->till('activate', $this->activateBody())->assertOk();

    $issued = app(ReissueKey::class)->handle($licence->refresh());
    $retired = RetiredLicenceKey::withoutCompanyScope()->sole();
    expect($retired->licence_id)->toBe($licence->id)
        ->and($retired->key_last4)->toBe('P8T5')
        ->and($retired->bound_device_hash)->not->toBeNull()->not->toBe(self::PC);

    $this->till('check-in', $this->checkInBody())->assertNotFound()->assertJsonPath('code', 'licence.not_found');
    $this->till('check-in', $this->checkInBody())->assertNotFound();

    $alert = LicenceAlert::withoutCompanyScope()->sole();
    expect($alert->type)->toBe(LicenceAlertType::ReissuedKeyUsed)
        ->and($alert->licence_id)->toBe($licence->id)
        ->and($alert->count)->toBe(2)
        ->and($alert->details['retiredKeyLast4'])->toBe('P8T5');

    // Another PC trying the old key gets the same 404 and no alert.
    $this->till('activate', $this->activateBody(device: self::OTHER_PC))->assertNotFound();
    expect(LicenceAlert::withoutCompanyScope()->count())->toBe(1);

    // The new key works on the same PC.
    $this->till('activate', $this->activateBody(key: $issued->plainKey()))->assertOk()->assertJsonPath('licence.id', $licence->id);
});

test('the admin licence page shows alerts and staff can resolve them', function () {
    [$company, $licence] = $this->keyedTenant();
    $this->till('activate', $this->activateBody())->assertOk();
    $this->till('activate', $this->activateBody(device: self::OTHER_PC, name: 'BACK-OFFICE'))->assertStatus(409);
    $alert = LicenceAlert::withoutCompanyScope()->sole();

    $this->actingAs($this->admin(AdminRole::Sales), 'admin')->get("/admin/licences/{$licence->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/licences/show')
            ->has('alerts', 1)
            ->where('alerts.0.type', 'sameKeyTwoDevices')
            ->where('alerts.0.label', 'Same key on two PCs')
            ->where('alerts.0.details.deviceName', 'BACK-OFFICE')
            ->where('alerts.0.details.boundDeviceName', 'FRONT-TILL')
            ->where('alerts.0.resolvedAt', null)
            ->where('activity.0.actorName', 'Till 1 (01) via the till')
            ->etc());

    $this->get("/admin/tenants/{$company->id}")
        ->assertInertia(fn (Assert $page) => $page->where('licensing.summary.openAlerts', 1)->etc());

    // Sales cannot resolve (licences.manage), support can.
    $this->post("/admin/licences/{$licence->id}/alerts/{$alert->id}/resolve")->assertForbidden();

    $support = $this->admin(AdminRole::Support);
    $this->actingAs($support, 'admin')->post("/admin/licences/{$licence->id}/alerts/{$alert->id}/resolve")
        ->assertRedirect()
        ->assertSessionHas('success', 'Alert marked as resolved.');

    $alert->refresh();
    expect($alert->resolved_at)->not->toBeNull()
        ->and($alert->resolved_by)->toBe($support->id)
        ->and(AuditLog::query()->where('action', 'licence.alert_resolved')->sole()->actor_id)->toBe((string) $support->id);

    $this->get("/admin/tenants/{$company->id}")->assertInertia(fn (Assert $page) => $page->where('licensing.summary.openAlerts', 0)->etc());

    // A repeat after resolving opens a new alert.
    $this->till('activate', $this->activateBody(device: self::OTHER_PC))->assertStatus(409);
    expect(LicenceAlert::withoutCompanyScope()->open()->count())->toBe(1);
});

test('an alert id from another licence is a 404 and guests are sent to login', function () {
    [, $licence] = $this->keyedTenant();
    [, $other] = $this->keyedTenant('Patel News', 1, 'PTL', self::OTHER_KEY);
    $this->till('activate', $this->activateBody(key: self::OTHER_KEY))->assertOk();
    $this->till('activate', $this->activateBody(key: self::OTHER_KEY, device: self::OTHER_PC))->assertStatus(409);
    $alert = LicenceAlert::withoutCompanyScope()->sole();

    $this->post("/admin/licences/{$other->id}/alerts/{$alert->id}/resolve")->assertRedirect('/admin/login');

    $this->actingAs($this->admin(), 'admin')->post("/admin/licences/{$licence->id}/alerts/{$alert->id}/resolve")->assertNotFound();
    expect($alert->refresh()->resolved_at)->toBeNull();
});
