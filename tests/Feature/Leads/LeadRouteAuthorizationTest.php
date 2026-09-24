<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadNote;
use App\Domain\Tenancy\Enums\CompanyRole;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Leads\LeadTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, LeadTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->standardPlan();
});

/**
 * Every lead route as [method, uri, kind]: read (tenants.view or leads.manage), work (leads.manage) or approve
 * (leads.manage + tenants.manage).
 *
 * @return list<array{0: string, 1: string, 2: string}>
 */
function leadRoutes(): array
{
    return [
        ['get', '/admin/leads', 'read'],
        ['get', '/admin/leads?view=board', 'read'],
        ['get', '/admin/leads/{lead}', 'read'],
        ['get', '/admin/leads/create', 'work'],
        ['post', '/admin/leads', 'work'],
        ['get', '/admin/leads/{lead}/edit', 'work'],
        ['put', '/admin/leads/{lead}', 'work'],
        ['post', '/admin/leads/{lead}/notes', 'work'],
        ['post', '/admin/leads/{lead}/assign', 'work'],
        ['post', '/admin/leads/{lead}/follow-up', 'work'],
        ['post', '/admin/leads/{lead}/contacted', 'work'],
        ['post', '/admin/leads/{lead}/reject', 'work'],
        ['post', '/admin/leads/{lead}/reopen', 'work'],
        ['delete', '/admin/leads/{lead}', 'work'],
        ['post', '/admin/leads/{lead}/restore', 'work'],
        ['post', '/admin/leads/{lead}/approve', 'approve'],
    ];
}

function leadUrl(string $uri, object $test): string
{
    return str_replace('{lead}', Lead::factory()->create()->id, $uri);
}

test('guests are sent to the admin login on every lead route', function () {
    foreach (leadRoutes() as [$method, $uri]) {
        $this->{$method}(leadUrl($uri, $this))->assertRedirect(route('admin.login'));
    }

    // Every lead action writes a timeline note: none ran.
    expect(LeadNote::query()->count())->toBe(0);
});

test('customer users cannot reach lead routes', function () {
    $customer = $this->addMember($this->tenant(), CompanyRole::Owner);

    foreach (leadRoutes() as [$method, $uri]) {
        $this->actingAs($customer)->{$method}(leadUrl($uri, $this))->assertRedirect(route('admin.login'));
    }
});

test('support and accounts staff can read leads but not work them', function (AdminRole $role) {
    $admin = $this->admin($role);

    foreach (leadRoutes() as [$method, $uri, $kind]) {
        $response = $this->actingAs($admin, 'admin')->{$method}(leadUrl($uri, $this));

        $kind === 'read' ? $response->assertOk() : $response->assertForbidden();
    }

    expect(LeadNote::query()->count())->toBe(0);
})->with([AdminRole::Support, AdminRole::Accounts]);

test('support and accounts see the lead page without actions', function (AdminRole $role) {
    $lead = Lead::factory()->create();

    $this->actingAs($this->admin($role), 'admin')->get("/admin/leads/{$lead->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.update', false)->where('can.approve', false)->where('approval', null));

    $this->actingAs($this->admin($role), 'admin')->get('/admin/leads')
        ->assertInertia(fn ($page) => $page->where('can.create', false));
})->with([AdminRole::Support, AdminRole::Accounts]);

test('owner and sales staff can work and approve leads', function (AdminRole $role) {
    $admin = $this->admin($role);

    foreach (leadRoutes() as [$method, $uri, $kind]) {
        if ($method !== 'get') {
            continue;
        }
        $this->actingAs($admin, 'admin')->get(leadUrl($uri, $this))->assertOk();
    }

    $lead = Lead::factory()->create();
    $this->actingAs($admin, 'admin')->get("/admin/leads/{$lead->id}")
        ->assertInertia(fn ($page) => $page->where('can.update', true)->where('can.approve', true)->whereNot('approval', null));

    $this->actingAs($admin, 'admin')
        ->post("/admin/leads/{$lead->id}/approve", ['shops' => [['name' => 'Leeds', 'code' => 'LDS', 'nation' => 'england', 'tills' => 1]]])
        ->assertRedirect(route('admin.tenants.show', $lead->fresh()->company_id));
})->with([AdminRole::Owner, AdminRole::Sales]);

test('inactive admins are signed out instead of reaching leads', function () {
    $admin = $this->salesAdmin();
    $admin->update(['is_active' => false]);

    $this->actingAs($admin, 'admin')->get('/admin/leads')->assertRedirect(route('admin.login'));
});

test('an unknown or malformed lead id is a 404', function () {
    $this->actingAs($this->admin(), 'admin')->get('/admin/leads/01K5ZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
    $this->actingAs($this->admin(), 'admin')->get('/admin/leads/not-a-ulid')->assertNotFound();
});

test('the Leads nav item links to the list and needs tenants.view like the page', function () {
    $nav = file_get_contents(resource_path('js/components/admin/admin-nav.ts'));
    preg_match("/\\{ title: 'Leads',[^}]*\\}/", (string) $nav, $match);

    expect($match[0] ?? '')->toContain("route: 'admin.leads.index'")
        ->toContain("activePattern: 'admin.leads.*'")
        ->toContain("ability: 'tenants.view'");
});
