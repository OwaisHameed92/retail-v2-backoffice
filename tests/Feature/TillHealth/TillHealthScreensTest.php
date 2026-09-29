<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Queries\AdminDashboard;
use App\Domain\Tenancy\Enums\CompanyRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;
use Tests\Feature\TillHealth\TillHealthHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, TillHealthHelpers::class);

/** Module 2.7: the admin Till health screens and the tenant dashboard's "Shops and tills". */
beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00', 'Europe/London'));

    $this->khan = $this->licensedTenant('Khan Mini Mart', 2, 'LDS');
    $this->patel = $this->licensedTenant('Patel News', 1, 'PNW');
    $this->khanTill = $this->registerOf($this->branchOf($this->khan, 'LDS'), '01');
    $this->khanLicence = $this->seen($this->licenceOf($this->khanTill), now()->subHour()->toImmutable());
    $this->patelTill = $this->registerOf($this->branchOf($this->patel, 'PNW'), '01');
    $this->patelLicence = $this->seen($this->licenceOf($this->patelTill), now()->subDays(4)->toImmutable(), ['last_app_version' => '0.0.9']);
    $this->refreshHealth();
});

test('guests are sent to the admin login and customers cannot reach the page', function () {
    $this->get('/admin/till-health')->assertRedirect(route('admin.login'));
    $this->getJson('/admin/till-health')->assertUnauthorized();

    $customer = $this->ownerOf($this->khan);
    $this->actingAs($customer)->get('/admin/till-health')->assertRedirect(route('admin.login'));
});

test('the page sits behind auth:admin and can:tenants.view (every role has it today), like its nav item', function () {
    $route = Route::getRoutes()->getByName('admin.till-health.index');
    expect($route->gatherMiddleware())->toContain('can:'.AdminRole::TENANTS_VIEW)
        ->and($route->gatherMiddleware())->toContain('auth:admin');

    $nav = file_get_contents(resource_path('js/components/admin/admin-nav.ts'));
    expect(preg_match("/title: 'Till health',[^}]*route: 'admin\\.till-health\\.index',[^}]*ability: 'tenants\\.view'/s", $nav))->toBe(1);
});

test('every admin role with tenants.view sees tills of every business', function (AdminRole $role) {
    $this->actingAs($this->admin($role), 'admin')->get('/admin/till-health')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/till-health/index')
            ->has('tills.data', 3)
            ->where('summary.tills', 3)
            ->where('summary.online', 1)
            ->where('summary.offline', 1)
            ->where('summary.notActivated', 1)
            ->where('summary.oldVersion', 1)
            ->where('thresholds.syncOnlineMinutes', 15));
})->with([AdminRole::Owner, AdminRole::Support, AdminRole::Sales, AdminRole::Accounts]);

test('filters, business scope and search narrow the list; problems come first', function () {
    $admin = $this->admin();

    $this->actingAs($admin, 'admin')->get('/admin/till-health?filter=offline')
        ->assertInertia(fn (AssertableInertia $page) => $page->has('tills.data', 1)
            ->where('tills.data.0.register.name', $this->patelTill->name)
            ->where('tills.data.0.company.name', 'Patel News')
            ->where('tills.data.0.problems.0.value', 'offline')
            ->where('filters.filter', 'offline'));

    $this->actingAs($admin, 'admin')->get('/admin/till-health?filter=oldVersion')->assertInertia(fn (AssertableInertia $page) => $page->has('tills.data', 1));
    $this->actingAs($admin, 'admin')->get('/admin/till-health?filter=clockSkew')->assertInertia(fn (AssertableInertia $page) => $page->has('tills.data', 0));

    $this->actingAs($admin, 'admin')->get('/admin/till-health?company='.$this->khan->id)
        ->assertInertia(fn (AssertableInertia $page) => $page->has('tills.data', 2)
            ->where('filters.company.name', 'Khan Mini Mart')
            ->where('summary.tills', 2));

    $this->actingAs($admin, 'admin')->get('/admin/till-health?search=Patel')->assertInertia(fn (AssertableInertia $page) => $page->has('tills.data', 1));

    $this->actingAs($admin, 'admin')->get('/admin/till-health')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tills.data.0.company.name', 'Patel News'));

    // Unknown filter values are ignored, never an error.
    $this->actingAs($admin, 'admin')->get('/admin/till-health?filter=nope&state=zzz&company=bad')
        ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('tills.data', 3)->where('filters.filter', null));
});

test('the tenant page shows each shop and till health, worked out now', function () {
    $this->actingAs($this->admin(), 'admin')->get(route('admin.tenants.show', $this->khan))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('branches.0.health.shop.state', 'online')
            ->where('branches.0.health.shop.tills', 2)
            ->where("branches.0.health.tills.{$this->khanTill->id}.state", 'online')
            ->where("branches.0.health.tills.{$this->khanTill->id}.installId", $this->khanLicence->device_id)
            ->missing("branches.0.health.tills.{$this->patelTill->id}"));
});

test('the licence page shows the till health card', function () {
    $this->actingAs($this->admin(AdminRole::Support), 'admin')->get(route('admin.licences.show', $this->patelLicence->id))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('health.till.state', 'offline')
            ->where('health.till.appOutdated', true)
            ->where('health.till.contractVersion', '1')
            ->where('health.shop.state', 'offline'));
});

test('the admin dashboard has the till health tile and the Till sync health row', function () {
    AdminDashboard::forget();

    $this->actingAs($this->admin(), 'admin')->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('dashboard.tills.online', 1)
            ->where('dashboard.tills.offline', 1)
            ->where('dashboard.health', fn ($health) => collect($health)->firstWhere('key', 'tills') === ['key' => 'tills', 'name' => 'Till sync', 'state' => 'degraded', 'detail' => '1 offline']));
});

test('the tenant dashboard shows only its own shops and tills, without install ids', function (CompanyRole $role) {
    $user = $this->addMember($this->khan, $role);

    $this->actingAs($user)->get('/app')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('app/dashboard')
            ->has('status.shops', 1)
            ->where('status.shops.0.name', $this->branchOf($this->khan, 'LDS')->name)
            ->has('status.shops.0.tills', 2)
            ->where('status.shops.0.tills.0.health.state', 'online')
            ->where('status.shops.0.tills.0.health.installId', null)
            ->where('status.shops.0.health.state', 'online'));

    $content = $this->actingAs($user)->get('/app')->getContent();
    expect($content)->not->toContain('Patel News')
        ->not->toContain($this->patelTill->id)
        ->not->toContain((string) $this->khanLicence->device_id);
})->with([CompanyRole::Owner, CompanyRole::Manager, CompanyRole::Accountant, CompanyRole::Staff]);

test('guests are sent to the login page from the tenant dashboard', function () {
    $this->get('/app')->assertRedirect('/login');
});
