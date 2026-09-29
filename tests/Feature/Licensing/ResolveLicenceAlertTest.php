<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Licensing\Actions\ResolveLicenceAlert;
use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Models\LicenceAlert;
use App\Domain\Shared\Models\AuditLog;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
});

function openAlert(Licence $licence, string $fingerprint = 'fp-1'): LicenceAlert
{
    return LicenceAlert::withoutCompanyScope()->create([
        'company_id' => $licence->company_id, 'licence_id' => $licence->id, 'type' => LicenceAlertType::SameKeyTwoDevices,
        'fingerprint' => $fingerprint, 'first_seen_at' => now(), 'last_seen_at' => now(), 'count' => 3,
    ]);
}

test('ResolveLicenceAlert closes an open alert once, with the admin and an audit entry', function () {
    $licence = $this->firstLicence($this->licensedTenant());
    $alert = openAlert($licence);
    $admin = $this->admin(AdminRole::Support);

    $resolved = app(ResolveLicenceAlert::class)->handle($alert, $admin);

    expect($resolved->isOpen())->toBeFalse()
        ->and($resolved->resolved_by)->toBe($admin->id)
        ->and($resolved->resolved_at)->not->toBeNull();

    $entry = AuditLog::query()->where('action', 'licence.alert_resolved')->sole();
    expect($entry->company_id)->toBe($licence->company_id)
        ->and($entry->meta)->toMatchArray(['alert_id' => $alert->id, 'count' => 3]);

    // Resolving again changes nothing and records nothing.
    $this->travel(1)->hours();
    $again = app(ResolveLicenceAlert::class)->handle($alert->fresh(), $this->admin(AdminRole::Owner));
    expect($again->resolved_by)->toBe($admin->id)
        ->and(AuditLog::query()->where('action', 'licence.alert_resolved')->count())->toBe(1);
});

test('the resolve route closes the alert of that licence only', function () {
    $licence = $this->firstLicence($this->licensedTenant());
    $other = $this->firstLicence($this->licensedTenant('Other Shop', 1, 'OTH'), 'OTH');
    $alert = openAlert($licence);
    $otherAlert = openAlert($other, 'fp-2');
    $admin = $this->admin(AdminRole::Support);

    $this->actingAs($admin, 'admin')
        ->post("/admin/licences/{$licence->id}/alerts/{$alert->id}/resolve")
        ->assertRedirect()->assertSessionHas('success', 'Alert marked as resolved.');
    expect($alert->fresh()->isOpen())->toBeFalse();

    // Another licence's alert through this licence's URL: not found, still open.
    $this->actingAs($admin, 'admin')->post("/admin/licences/{$licence->id}/alerts/{$otherAlert->id}/resolve")->assertNotFound();
    expect($otherAlert->fresh()->isOpen())->toBeTrue();
});

test('guests and admins without licences.manage cannot resolve alerts', function () {
    $licence = $this->firstLicence($this->licensedTenant());
    $alert = openAlert($licence);
    $url = "/admin/licences/{$licence->id}/alerts/{$alert->id}/resolve";

    $this->post($url)->assertRedirect(route('admin.login'));
    $this->actingAs($this->admin(AdminRole::Sales), 'admin')->post($url)->assertForbidden();
    expect($alert->fresh()->isOpen())->toBeTrue();
});
