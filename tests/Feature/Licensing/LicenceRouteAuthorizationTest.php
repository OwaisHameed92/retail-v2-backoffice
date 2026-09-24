<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Tenancy\Enums\CompanyRole;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
});

/**
 * Every module 1.3 route as [method, uri, ability needed]. "view" = tenants.view, "manage" = licences.manage,
 * "keys" = licences.manage or tenants.manage.
 *
 * @return list<array{0: string, 1: string, 2: string}>
 */
function licenceRoutes(): array
{
    return [
        ['get', '/admin/licences', 'view'],
        ['get', '/admin/licences/{licence}', 'view'],
        ['get', '/admin/search?q=khan', 'view'],
        ['put', '/admin/licences/{licence}/notes', 'manage'],
        ['post', '/admin/licences/{licence}/renew', 'manage'],
        ['post', '/admin/licences/{licence}/plan', 'manage'],
        ['post', '/admin/licences/{licence}/reset-device', 'manage'],
        ['post', '/admin/licences/{licence}/reissue', 'manage'],
        ['post', '/admin/licences/{licence}/suspend', 'manage'],
        ['post', '/admin/licences/{licence}/unsuspend', 'manage'],
        ['post', '/admin/licences/{licence}/revoke', 'manage'],
        ['post', '/admin/licences/email-keys', 'keys'],
        ['post', '/admin/tenants/{company}/licences/issue-missing', 'manage'],
        ['post', '/admin/tenants/{company}/licences/renew', 'manage'],
        ['put', '/admin/tenants/{company}/plan', 'manage'],
        ['post', '/admin/tenants/{company}/registers/{register}/licence', 'manage'],
    ];
}

function fillLicenceRoute(string $uri, object $test): string
{
    $company = $test->licensedTenant('Khan '.uniqid(), 1, 'KHN');
    $register = $test->registerOf($test->branchOf($company, 'KHN'), '01');

    return strtr($uri, ['{company}' => $company->id, '{register}' => $register->id, '{licence}' => $test->licenceOf($register)->id]);
}

test('guests are sent to the admin login, or get 401 as JSON', function () {
    foreach (licenceRoutes() as [$method, $uri]) {
        $url = fillLicenceRoute($uri, $this);

        $this->{$method}($url)->assertRedirect(route('admin.login'));
        $this->{$method.'Json'}($url)->assertUnauthorized();
    }
});

test('customer users cannot reach licence admin routes', function () {
    foreach (licenceRoutes() as [$method, $uri]) {
        $url = fillLicenceRoute($uri, $this);
        $customer = $this->addMember($this->licensedTenant('Other '.uniqid(), 1, 'OTH'), CompanyRole::Owner);

        $this->actingAs($customer)->{$method}($url)->assertRedirect(route('admin.login'));
        $this->actingAs($customer)->{$method.'Json'}($url)->assertUnauthorized();
    }
});

test('each role reaches exactly the licence routes its abilities allow', function (AdminRole $role) {
    $admin = $this->admin($role);

    foreach (licenceRoutes() as [$method, $uri, $needs]) {
        $allowed = match ($needs) {
            'view' => $role->can(AdminRole::TENANTS_VIEW),
            'manage' => $role->can(AdminRole::LICENCES_MANAGE),
            'keys' => $role->can(AdminRole::LICENCES_MANAGE) || $role->can(AdminRole::TENANTS_MANAGE),
        };

        $status = $this->actingAs($admin, 'admin')->{$method.'Json'}(fillLicenceRoute($uri, $this))->status();

        expect($status === 403)->toBe(! $allowed, "{$role->value} {$method} {$uri} gave {$status}");
    }
})->with([AdminRole::Owner, AdminRole::Support, AdminRole::Sales, AdminRole::Accounts]);

test('inactive admins are signed out instead of reaching licences', function () {
    $admin = $this->admin(AdminRole::Support);
    $admin->update(['is_active' => false]);

    $this->actingAs($admin, 'admin')->get('/admin/licences')->assertRedirect(route('admin.login'));
});

test('the Licences nav item needs tenants.view like the page', function () {
    $nav = file_get_contents(resource_path('js/components/admin/admin-nav.ts'));

    // Match the Licences entry however it is formatted (one line or one property per line).
    expect(preg_match("/title: 'Licences',[^}]*route: 'admin\\.licences\\.index',[^}]*ability: 'tenants\\.view'/s", $nav))->toBe(1);
});
