<?php

use App\Domain\Tenancy\Actions\SuspendCompany;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->actingAs($this->admin(), 'admin');
});

function validTenantForm(array $overrides = []): array
{
    return array_merge([
        'name' => 'Patel News',
        'legal_name' => 'Patel News Ltd',
        'vat_number' => 'gb 123 4567 89',
        'company_number' => '1234567',
        'email' => 'Hello@PatelNews.test',
        'phone' => '0113 496 0000',
        'contact_name' => 'Raj Patel',
        'address' => '1 Market St, Leeds',
        'notes' => 'Met at the trade show.',
        'status' => 'trial',
        'trial_ends_at' => '',
        'branch_code' => 'lds',
        'branch_name' => 'Leeds',
        'branch_nation' => 'england',
        'branch_address' => '1 Market St',
        'branch_phone' => '',
        'branch_vat_number' => '',
        'branch_area_m2' => '60',
        'branch_is_drs_return_point' => true,
        'tills' => 3,
        'owner_name' => 'Raj Patel',
        'owner_email' => 'Raj@PatelNews.test',
    ], $overrides);
}

test('the list shows tenants with counts, owner and status', function () {
    $company = $this->tenant('Khan Mini Mart', tills: 2, ownerEmail: 'aisha@khan.test');

    $this->get('/admin/tenants')->assertOk()->assertInertia(fn ($page) => $page
        ->component('admin/tenants/index')
        ->has('tenants.data', 1)
        ->where('tenants.data.0.id', $company->id)
        ->where('tenants.data.0.name', 'Khan Mini Mart')
        ->where('tenants.data.0.status', 'trial')
        ->where('tenants.data.0.branchesCount', 1)
        ->where('tenants.data.0.registersCount', 2)
        ->where('tenants.data.0.ownerEmail', 'aisha@khan.test')
        ->where('tenants.meta.total', 1)
        ->where('counts.trial', 1)
        ->where('canManage', true));
});

test('the list searches name and owner email, filters by status and sorts by tills', function () {
    $this->tenant('Alpha Stores', tills: 1, ownerEmail: 'zed@alpha.test');
    $bravo = $this->tenant('Bravo Mart', tills: 3);
    app(SuspendCompany::class)->handle($bravo, 'Unpaid');

    $this->get('/admin/tenants?search=bravo')->assertInertia(fn ($page) => $page->has('tenants.data', 1)->where('tenants.data.0.name', 'Bravo Mart'));
    $this->get('/admin/tenants?search=zed@alpha')->assertInertia(fn ($page) => $page->has('tenants.data', 1)->where('tenants.data.0.name', 'Alpha Stores'));
    $this->get('/admin/tenants?status=suspended')->assertInertia(fn ($page) => $page->has('tenants.data', 1)->where('filters.status', 'suspended'));
    $this->get('/admin/tenants?status=nonsense')->assertInertia(fn ($page) => $page->has('tenants.data', 2)->where('filters.status', null));
    $this->get('/admin/tenants?sort=active_registers_count&direction=desc')
        ->assertInertia(fn ($page) => $page->where('tenants.data.0.name', 'Bravo Mart')->where('tenants.data.1.name', 'Alpha Stores'));
});

test('the create wizard makes the whole tenant and cleans the input', function () {
    $response = $this->post('/admin/tenants', validTenantForm());

    $company = Company::query()->where('name', 'Patel News')->firstOrFail();
    $response->assertRedirect(route('admin.tenants.show', $company))->assertSessionHas('success');

    $branch = $this->branchOf($company);
    expect($company->vat_number)->toBe('GB123456789')
        ->and($company->company_number)->toBe('01234567')
        ->and($company->email)->toBe('hello@patelnews.test')
        ->and($company->notes)->toBe('Met at the trade show.')
        ->and($branch->is_drs_return_point)->toBeTrue()
        ->and($branch->area_m2)->toBe('60.00')
        ->and($branch->phone)->toBeNull()
        ->and($this->mainTillCode($branch))->toBe('01')
        ->and(User::query()->where('email', 'raj@patelnews.test')->exists())->toBeTrue();
});

test('the create wizard explains what is wrong', function () {
    $this->post('/admin/tenants', validTenantForm([
        'name' => '',
        'vat_number' => '12345',
        'branch_code' => 'L1',
        'tills' => 25,
        'owner_email' => 'not-an-email',
        'branch_licensed_hours_json' => '{nope',
    ]))->assertSessionHasErrors([
        'name' => 'Enter the business name.',
        'vat_number' => 'Enter a UK VAT number like GB123456789.',
        'branch_code' => 'Use 2 to 5 capital letters, for example LDS.',
        'tills' => 'Add up to 20 tills now; you can add more later.',
        'owner_email',
        'branch_licensed_hours_json',
    ]);

    expect(Company::query()->count())->toBe(0);
});

test('a trial end date is saved as the end of that day in London', function () {
    $this->post('/admin/tenants', validTenantForm(['trial_ends_at' => '2026-10-01']));

    expect(Company::query()->firstOrFail()->trial_ends_at?->toIso8601String())->toBe('2026-10-01T22:59:59+00:00');
});

test('the detail page has branches with tills, users, activity and options', function () {
    $company = $this->tenant(tills: 2);

    $this->get("/admin/tenants/{$company->id}")->assertOk()->assertInertia(fn ($page) => $page
        ->component('admin/tenants/show')
        ->where('tenant.id', $company->id)
        ->where('stats.branches', 1)
        ->where('stats.tills', 2)
        ->where('stats.users', 1)
        ->has('branches', 1)
        ->where('branches.0.code', 'LDS')
        ->has('branches.0.registers', 2)
        ->where('branches.0.registers.0.isMainTill', true)
        ->has('members', 1)
        ->where('members.0.role', 'owner')
        ->where('activity.data.0.actorKind', 'admin')
        ->where('activity.meta.perPage', 10)
        ->has('nations', 4)
        ->has('roles', 4));
});

test('the activity tab describes what happened', function () {
    $company = $this->tenant(tills: 1);

    $this->get("/admin/tenants/{$company->id}?perPage=50")->assertInertia(function ($page) {
        $descriptions = collect($page->toArray()['props']['activity']['data'])->pluck('description');
        expect($descriptions)->toContain('Created the business', 'Added branch Leeds', 'Added till Till 1 (01) at Leeds');
    });
});

test('another company\'s activity never shows', function () {
    $alpha = $this->tenant('Alpha');
    $this->tenant('Bravo', code: 'BRV');

    $this->get("/admin/tenants/{$alpha->id}?perPage=100")->assertInertia(function ($page) {
        expect(collect($page->toArray()['props']['activity']['data'])->pluck('description')->implode(' '))->not->toContain('BRV');
    });
});

test('business details can be edited', function () {
    $company = $this->tenant();

    $this->get("/admin/tenants/{$company->id}/edit")->assertInertia(fn ($page) => $page->component('admin/tenants/edit')->where('tenant.id', $company->id));

    $this->put("/admin/tenants/{$company->id}", ['name' => 'Khan Superstore', 'phone' => 'abc'])->assertSessionHasErrors(['phone']);
    $this->put("/admin/tenants/{$company->id}", ['name' => 'Khan Superstore', 'notes' => 'VIP'])
        ->assertRedirect(route('admin.tenants.show', $company))
        ->assertSessionHas('success', 'Business details saved.');

    expect($company->fresh()->name)->toBe('Khan Superstore')->and($company->fresh()->notes)->toBe('VIP');
});

test('status actions work over http and need a reason where it matters', function () {
    $company = $this->tenant();
    $base = "/admin/tenants/{$company->id}";

    $this->post("{$base}/suspend", ['reason' => ''])->assertSessionHasErrors('reason');
    $this->post("{$base}/suspend", ['reason' => 'Unpaid invoice'])->assertSessionHas('success');
    expect($company->fresh()->status)->toBe(CompanyStatus::Suspended);

    $this->post("{$base}/activate")->assertSessionHasErrors('status');
    $this->post("{$base}/unsuspend")->assertSessionHas('success');
    $this->post("{$base}/activate")->assertSessionHas('success');
    $this->post("{$base}/cancel", ['reason' => 'Closed'])->assertSessionHas('success');
    expect($company->fresh()->status)->toBe(CompanyStatus::Cancelled);
});

test('branches and tills are managed over http', function () {
    $company = $this->tenant(tills: 1);
    $base = "/admin/tenants/{$company->id}";

    $this->post("{$base}/branches", ['code' => 'LDS', 'name' => 'Dup', 'nation' => 'england', 'tills' => 1])
        ->assertSessionHasErrors(['code' => 'Another branch of this business already uses this code.']);
    $this->post("{$base}/branches", ['code' => 'bfd', 'name' => 'Bradford', 'nation' => 'wales', 'tills' => 2])->assertSessionHas('success');

    $bradford = $this->branchOf($company, 'BFD');
    expect($bradford->registers()->withoutGlobalScopes()->count())->toBe(2);

    $this->put("{$base}/branches/{$bradford->id}", ['code' => 'BRD', 'name' => 'Bradford Centre', 'nation' => 'england'])->assertSessionHas('success');
    expect($bradford->fresh()->code)->toBe('BRD');

    $this->allowTills($bradford, 3);
    $this->post("{$base}/branches/{$bradford->id}/registers", ['name' => 'Kiosk', 'is_main_till' => true])->assertSessionHas('success');
    expect($this->mainTillCode($bradford))->toBe('03');

    $till = $this->registerOf($bradford, '01');
    $this->put("{$base}/registers/{$till->id}", ['name' => 'Front', 'code' => '1'])->assertSessionHasErrors('code');
    $this->put("{$base}/registers/{$till->id}", ['name' => 'Front', 'code' => '01'])->assertSessionHas('success');
    $this->post("{$base}/registers/{$till->id}/main")->assertSessionHas('success');
    expect($this->mainTillCode($bradford))->toBe('01');
    $this->post("{$base}/registers/{$till->id}/deactivate")->assertSessionHas('success');
    expect($this->mainTillCode($bradford))->toBe('02');
    $this->post("{$base}/registers/{$till->id}/reactivate")->assertSessionHas('success');

    $this->post("{$base}/branches/{$bradford->id}/deactivate")->assertSessionHas('success');
    $this->post("{$base}/branches/{$this->branchOf($company)->id}/deactivate")->assertSessionHasErrors('branch');
    $this->post("{$base}/branches/{$bradford->id}/reactivate")->assertSessionHas('success');
});

test('another company\'s branch, till or user is not found through this company', function () {
    $alpha = $this->tenant('Alpha');
    $bravo = $this->tenant('Bravo', code: 'BRV');
    $bravoBranch = $this->branchOf($bravo, 'BRV');
    $bravoTill = $this->registerOf($bravoBranch, '01');
    $bravoOwner = $this->ownerOf($bravo);
    $base = "/admin/tenants/{$alpha->id}";

    $this->put("{$base}/branches/{$bravoBranch->id}", ['code' => 'XX', 'name' => 'X', 'nation' => 'england'])->assertNotFound();
    $this->post("{$base}/branches/{$bravoBranch->id}/deactivate")->assertNotFound();
    $this->post("{$base}/branches/{$bravoBranch->id}/registers")->assertNotFound();
    $this->post("{$base}/registers/{$bravoTill->id}/deactivate")->assertNotFound();
    $this->put("{$base}/users/{$bravoOwner->id}", ['role' => 'staff'])->assertNotFound();
    $this->delete("{$base}/users/{$bravoOwner->id}")->assertNotFound();

    expect(Branch::withoutCompanyScope()->whereKey($bravoBranch->id)->value('is_active'))->toBeTruthy();
});

test('users are managed over http', function () {
    $company = $this->tenant(ownerEmail: 'owner@khan.test');
    $base = "/admin/tenants/{$company->id}";

    $this->post("{$base}/users", ['name' => 'Sam', 'email' => 'sam@khan.test', 'role' => 'wizard'])->assertSessionHasErrors('role');
    $this->post("{$base}/users", ['name' => 'Sam', 'email' => 'sam@khan.test', 'role' => 'manager'])->assertSessionHas('success');
    $sam = User::query()->where('email', 'sam@khan.test')->firstOrFail();

    $this->put("{$base}/users/{$sam->id}", ['role' => 'staff'])->assertSessionHas('success');
    $this->post("{$base}/users/{$sam->id}/password-link")->assertSessionHas('success');
    $this->put("{$base}/users/{$this->ownerOf($company)->id}", ['role' => 'staff'])->assertSessionHasErrors('user');
    $this->delete("{$base}/users/{$sam->id}")->assertSessionHas('success');

    expect($company->users()->whereKey($sam->id)->exists())->toBeFalse();
});
