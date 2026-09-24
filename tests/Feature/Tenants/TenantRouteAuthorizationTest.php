<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Tenancy\Enums\CompanyRole;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
});

/**
 * Every tenant admin route as [method, uri, needs tenants.manage]. Placeholders are filled per test.
 *
 * @return list<array{0: string, 1: string, 2: bool}>
 */
function tenantRoutes(): array
{
    return [
        ['get', '/admin/tenants', false],
        ['get', '/admin/tenants/{company}', false],
        ['get', '/admin/tenants/create', true],
        ['post', '/admin/tenants', true],
        ['get', '/admin/tenants/{company}/edit', true],
        ['put', '/admin/tenants/{company}', true],
        ['post', '/admin/tenants/{company}/activate', true],
        ['post', '/admin/tenants/{company}/suspend', true],
        ['post', '/admin/tenants/{company}/unsuspend', true],
        ['post', '/admin/tenants/{company}/cancel', true],
        ['post', '/admin/tenants/{company}/branches', true],
        ['put', '/admin/tenants/{company}/branches/{branch}', true],
        ['post', '/admin/tenants/{company}/branches/{branch}/deactivate', true],
        ['post', '/admin/tenants/{company}/branches/{branch}/reactivate', true],
        ['post', '/admin/tenants/{company}/branches/{branch}/registers', true],
        ['put', '/admin/tenants/{company}/registers/{register}', true],
        ['post', '/admin/tenants/{company}/registers/{register}/main', true],
        ['post', '/admin/tenants/{company}/registers/{register}/deactivate', true],
        ['post', '/admin/tenants/{company}/registers/{register}/reactivate', true],
        ['post', '/admin/tenants/{company}/users', true],
        ['put', '/admin/tenants/{company}/users/{user}', true],
        ['delete', '/admin/tenants/{company}/users/{user}', true],
        ['post', '/admin/tenants/{company}/users/{user}/password-link', true],
        ['post', '/admin/tenants/{company}/impersonate', true],
    ];
}

function fillTenantRoute(string $uri, object $test): string
{
    $company = $test->tenant(tills: 1);
    $branch = $test->branchOf($company);

    return strtr($uri, [
        '{company}' => $company->id,
        '{branch}' => $branch->id,
        '{register}' => $test->registerOf($branch, '01')->id,
        '{user}' => (string) $test->ownerOf($company)->id,
    ]);
}

test('guests are sent to the admin login on every tenant route', function () {
    foreach (tenantRoutes() as [$method, $uri]) {
        $this->{$method}(fillTenantRoute($uri, $this))->assertRedirect(route('admin.login'));
    }
});

test('customer users cannot reach tenant admin routes', function () {
    foreach (tenantRoutes() as [$method, $uri]) {
        $url = fillTenantRoute($uri, $this);
        $customer = $this->addMember($this->tenant('Other '.uniqid(), code: 'OTH'), CompanyRole::Owner);

        $this->actingAs($customer)->{$method}($url)->assertRedirect(route('admin.login'));
    }
});

test('accounts staff can view tenants but not change them', function () {
    $admin = $this->admin(AdminRole::Accounts);

    foreach (tenantRoutes() as [$method, $uri, $manage]) {
        $response = $this->actingAs($admin, 'admin')->{$method}(fillTenantRoute($uri, $this));

        $manage ? $response->assertForbidden() : $response->assertOk();
    }
});

test('owner, sales and support staff can open the manage screens', function (AdminRole $role) {
    $admin = $this->admin($role);
    $company = $this->tenant();

    $this->actingAs($admin, 'admin')->get('/admin/tenants')->assertOk();
    $this->actingAs($admin, 'admin')->get('/admin/tenants/create')->assertOk();
    $this->actingAs($admin, 'admin')->get("/admin/tenants/{$company->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.manage', true)->where('can.impersonate', true));
    $this->actingAs($admin, 'admin')->get("/admin/tenants/{$company->id}/edit")->assertOk();
})->with([AdminRole::Owner, AdminRole::Sales, AdminRole::Support]);

test('accounts staff see the tenant page without manage actions', function () {
    $company = $this->tenant();

    $this->actingAs($this->admin(AdminRole::Accounts), 'admin')->get("/admin/tenants/{$company->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.manage', false)->where('can.impersonate', false));
});

test('inactive admins are signed out instead of reaching tenants', function () {
    $admin = $this->admin();
    $admin->update(['is_active' => false]);

    $this->actingAs($admin, 'admin')->get('/admin/tenants')->assertRedirect(route('admin.login'));
});
