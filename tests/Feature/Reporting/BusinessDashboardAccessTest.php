<?php

use App\Domain\Tenancy\Actions\SwitchCurrentBranch;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Reporting\BusinessDashboardHelpers as H;
use Tests\Feature\TillData\TillFixtures;

/*
 * Module 3.3: who sees the business dashboard's figures, and only their own. Every basket is the sample's (net £4.53).
 * "Now" is Wednesday 23 Sept 2026, 18:00 London. Kirkgate today: Leeds 4 baskets (2 tills), Bradford 1 = £22.65.
 */

beforeEach(function () {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-09-23 18:00', 'Europe/London'));
    [$this->kirkgate, $this->leeds, $this->bradford] = TillFixtures::tenant();
    $this->other = Company::factory()->create(['name' => 'Other Stores']);
    $this->otherShop = Branch::factory()->forCompany($this->other)->create(['code' => 'OTH', 'name' => 'Other shop']);
    $this->otherTill = Register::factory()->forBranch($this->otherShop)->create(['code' => '01', 'is_main_till' => true]);

    foreach ([300001, 300002, 300003] as $i => $n) {
        H::basket($this->kirkgate, $this->leeds, TillFixtures::TILL_1, $n, sprintf('2026-09-23T%02d:10:00Z', 8 + $i));
    }
    H::basket($this->kirkgate, $this->leeds, TillFixtures::TILL_2, 300004, '2026-09-23T12:00:00Z');
    H::basket($this->kirkgate, $this->bradford, TillFixtures::BRADFORD_TILL, 300005, '2026-09-23T13:00:00Z');
    H::basket($this->other, $this->otherShop, $this->otherTill->id, 300006, '2026-09-23T15:00:00Z');
    H::stock($this->kirkgate->id, TillFixtures::LEEDS, 'PLOW1', '1');
    H::stock($this->kirkgate->id, TillFixtures::BRADFORD, 'PLOW2', '2');
});

function member(Company $company, CompanyRole $role, ?string $branchId = null): User
{
    $user = User::factory()->create();
    $company->users()->attach($user->id, ['role' => $role->value, 'is_active' => true, 'branch_id' => $branchId]);

    return $user;
}

test('guests go to the login page; staff see shops and tills but no figures; owner, manager and accountant get the figures', function () {
    $this->get('/app')->assertRedirect('/login');
    $this->get('/app?period=last7Days')->assertRedirect('/login');

    $staff = member($this->kirkgate, CompanyRole::Staff);
    $this->actingAs($staff)->get('/app')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('app/dashboard')
            ->where('canSales', false)
            ->where('sales', null)
            ->has('status.shops', 2)
            // Asking for the figures directly (a partial reload) still gives nothing.
            ->reloadOnly('sales', fn (Assert $reload) => $reload->where('sales', null)));

    foreach ([CompanyRole::Owner, CompanyRole::Manager, CompanyRole::Accountant] as $role) {
        $this->actingAs(member($this->kirkgate, $role))->get('/app')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canSales', true)->missing('sales')
                ->loadDeferredProps(fn (Assert $reload) => $reload->where('sales.kpis.headline.net.value', '22.65')));
    }
});

test('a business never sees another business\'s figures, shops or tills', function () {
    $this->actingAs(member($this->other, CompanyRole::Owner))->get('/app?till='.TillFixtures::TILL_1)
        ->assertInertia(fn (Assert $page) => $page->where('context.branch', null)->where('filters.till', null)->has('status.shops', 1)
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('sales.kpis.headline.net.value', '4.53')
                ->where('sales.kpis.totals.transactions', 1)
                ->where('sales.shops.0.label', 'Other shop')
                ->has('sales.shops', 1)
                ->where('sales.operations.lowStock.total', 0)
                ->has('sales.freshness.shops', 1)));

    // Picking another business's shop in the switcher is refused; the dashboard stays on every shop of its own.
    $owner = member($this->kirkgate, CompanyRole::Owner);
    $this->actingAs($owner)->post(route('app.branch.switch'), ['branch_id' => $this->otherShop->id])->assertSessionHasErrors('branch_id');
    $this->actingAs($owner)->get('/app?till='.$this->otherTill->id)
        ->assertInertia(fn (Assert $page) => $page->where('context.branch', null)->where('filters.till', null)
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('sales.kpis.headline.net.value', '22.65')
                ->where('sales.operations.lowStock.total', 2)));

    // Even a stale session naming another business's shop shows nothing of it.
    $this->actingAs($owner)->withSession([SwitchCurrentBranch::SESSION_KEY => $this->otherShop->id])->get('/app')
        ->assertInertia(fn (Assert $page) => $page->where('context.branch', null)
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('sales.kpis.headline.net.value', '22.65')));
});

test('a shop manager limited to one shop sees only that shop and cannot switch away', function () {
    $manager = member($this->kirkgate, CompanyRole::Manager, TillFixtures::BRADFORD);

    $this->actingAs($manager)->get('/app?till='.TillFixtures::TILL_1)
        ->assertInertia(fn (Assert $page) => $page
            ->where('context.restricted', true)
            ->where('context.branch.name', 'Bradford')
            ->where('filters.branch', TillFixtures::BRADFORD)
            ->where('filters.till', null)
            ->where('branchLocked', true)
            ->where('currentBranchId', TillFixtures::BRADFORD)
            ->has('branches', 1)
            ->where('branches.0.id', TillFixtures::BRADFORD)
            ->has('status.shops', 1)
            ->where('status.shops.0.name', 'Bradford')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('sales.level', 'shop')
                ->where('sales.kpis.headline.net.value', '4.53')
                ->where('sales.shops', null)
                ->where('sales.tills.0.label', '01 – Till 1')
                ->has('sales.tills', 1)
                ->where('sales.operations.lowStock.total', 1)
                ->has('sales.freshness.shops', 1)
                ->where('sales.freshness.shops.0.name', 'Bradford')));

    $this->actingAs($manager)->post(route('app.branch.switch'), ['branch_id' => TillFixtures::LEEDS])->assertSessionHasErrors('branch_id');
    $this->actingAs($manager)->post(route('app.branch.switch'), ['branch_id' => null])->assertSessionHasErrors('branch_id');
    $this->actingAs($manager)->withSession([SwitchCurrentBranch::SESSION_KEY => TillFixtures::LEEDS])->get('/app')
        ->assertInertia(fn (Assert $page) => $page->where('context.branch.name', 'Bradford')
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('sales.kpis.headline.net.value', '4.53')));
});

test('a user limited to a shop that no longer exists sees nothing, never every shop', function () {
    $manager = member($this->kirkgate, CompanyRole::Manager, '01K5T0Q8C4000000000000B999');

    $this->actingAs($manager)->get('/app')
        ->assertInertia(fn (Assert $page) => $page->where('context.restricted', true)->has('status.shops', 0)
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('sales.kpis.headline.net.value', '0.00')
                ->where('sales.freshness.hasData', false)
                ->where('sales.operations.lowStock.total', 0)));
});
