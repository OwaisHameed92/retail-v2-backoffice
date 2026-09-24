<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Licensing\LicenceKey;
use App\Domain\Tenancy\Enums\CompanyRole;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class);

beforeEach(fn () => Mail::fake());

test('it finds tenants by name, legal name and owner email', function () {
    $company = $this->licensedTenant('Khan Mini Mart', 1);
    $this->licensedTenant('Patel News', 1, 'PTL');
    $admin = $this->admin(AdminRole::Accounts);

    foreach (['khan mini', 'Mini Mart Ltd', 'khan-mini-mart@owner'] as $term) {
        $this->actingAs($admin, 'admin')->getJson('/admin/search?q='.urlencode($term))
            ->assertOk()
            ->assertJsonCount(1, 'tenants')
            ->assertJsonPath('tenants.0.id', $company->id)
            ->assertJsonPath('tenants.0.url', route('admin.tenants.show', $company));
    }
});

test('it finds licences by last 4, full key, device id and device name', function () {
    $company = $this->licensedTenant('Khan Mini Mart', 1);
    $register = $this->registerOf($this->branchOf($company), '01');
    $this->licenceOf($register)->delete();
    $issued = $this->issue($register);
    $this->activate($issued->licence, deviceId: 'HW-5521-ABC', deviceName: 'KIOSK-PC');
    $admin = $this->admin(AdminRole::Support);
    $last4 = LicenceKey::parse($issued->plainKey())->last4();

    foreach ([$last4, $issued->plainKey(), 'hw-5521', 'kiosk'] as $term) {
        $this->actingAs($admin, 'admin')->getJson('/admin/search?q='.urlencode($term))
            ->assertOk()
            ->assertJsonPath('licences.0.id', $issued->licence->id)
            ->assertJsonPath('licences.0.maskedKey', 'SSP-••••-••••-••••-'.$last4)
            ->assertJsonPath('licences.0.businessName', 'Khan Mini Mart')
            ->assertJsonPath('licences.0.url', route('admin.licences.show', $issued->licence->id));
    }

    $json = $this->actingAs($admin, 'admin')->getJson('/admin/search?q='.urlencode($issued->plainKey()))->json();
    expect(json_encode($json))->not->toContain(LicenceKey::parse($issued->plainKey())->body());
});

test('it returns at most 5 of each and nothing for one character', function () {
    for ($i = 1; $i <= 7; $i++) {
        $this->licensedTenant("Corner Shop {$i}", 1, 'CS'.chr(64 + $i));
    }
    $admin = $this->admin();

    $this->actingAs($admin, 'admin')->getJson('/admin/search?q=corner')->assertJsonCount(5, 'tenants');
    $this->actingAs($admin, 'admin')->getJson('/admin/search?q=c')->assertJsonCount(0, 'tenants')->assertJsonCount(0, 'licences');
});

test('deleted businesses and their licences are not found', function () {
    $company = $this->licensedTenant('Khan Mini Mart', 1);
    $this->activate($this->firstLicence($company), deviceName: 'GONE-PC');
    $company->delete();

    $this->actingAs($this->admin(), 'admin')->getJson('/admin/search?q=khan')->assertJsonCount(0, 'tenants');
    $this->actingAs($this->admin(), 'admin')->getJson('/admin/search?q=gone-pc')->assertJsonCount(0, 'licences');
});

test('only admins can search', function () {
    $this->getJson('/admin/search?q=khan')->assertUnauthorized();

    $customer = $this->addMember($this->licensedTenant(), CompanyRole::Owner);
    $this->actingAs($customer)->getJson('/admin/search?q=khan')->assertUnauthorized();
});
