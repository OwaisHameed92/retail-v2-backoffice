<?php

use App\Domain\Sales\Data\SaleFilters;
use App\Domain\Sales\Queries\SaleSearch;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Domain\TillData\Models\Sale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Reporting\BusinessDashboardHelpers as H;
use Tests\Feature\Reporting\ReportFixtures as R;
use Tests\Feature\TillData\TillFixtures as T;

/*
 * Module 4.6: the sales list (filters, keyset paging, capped count), who may see it, one-shop users and tenant
 * isolation. "Now" is Friday 25 Sept 2026, 12:00 London. Every basket is the push sample's (£5.15: bread + 2 cola, card).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00', 'Europe/London'));
    [$this->company, $this->leeds, $this->bradford] = T::tenant();
    $this->other = Company::factory()->create(['name' => 'Other Stores']);
    $this->otherShop = Branch::factory()->forCompany($this->other)->create(['code' => 'OTH', 'name' => 'Other shop']);
    $this->otherTill = Register::factory()->forBranch($this->otherShop)->create(['code' => '01', 'is_main_till' => true]);

    H::basket($this->company, $this->leeds, T::TILL_1, 300001, '2026-09-23T08:10:00Z');
    H::basket($this->company, $this->leeds, T::TILL_2, 300002, '2026-09-24T09:00:00Z', ['user' => R::USER_2]);
    H::basket($this->company, $this->bradford, T::BRADFORD_TILL, 300003, '2026-09-24T10:00:00Z');
    H::basket($this->company, $this->leeds, T::TILL_1, 300004, '2026-09-24T11:00:00Z', ['type' => 'refund', 'original' => R::saleId('300001')]);
    H::basket($this->company, $this->leeds, T::TILL_1, 300005, '2026-09-24T12:00:00Z', ['status' => 'voided']);
    H::basket($this->other, $this->otherShop, $this->otherTill->id, 300009, '2026-09-24T13:00:00Z');

    $this->owner = salesMember($this->company, CompanyRole::Owner);
    $this->receipts = fn (User $user, string $query = '') => collect($this->actingAs($user)->get('/app/sales'.$query)->assertOk()
        ->viewData('page')['props']['sales']['data'])->pluck('receiptNumber')->all();
});

function salesMember(Company $company, CompanyRole $role, ?string $branchId = null): User
{
    $user = User::factory()->create();
    $company->users()->attach($user->id, ['role' => $role->value, 'is_active' => true, 'branch_id' => $branchId]);

    return $user;
}

test('guests go to the login page; owner, manager, accountant and staff may look; a role without sales.view gets 403', function () {
    $this->get('/app/sales')->assertRedirect('/login');
    $this->get('/app/sales/'.R::saleId('300001'))->assertRedirect('/login');
    $this->get('/app/sales/export')->assertRedirect('/login');

    foreach ([CompanyRole::Owner, CompanyRole::Manager, CompanyRole::Accountant, CompanyRole::Staff] as $role) {
        $this->actingAs(salesMember($this->company, $role))->get('/app/sales')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('app/sales/index'));
    }

    $this->partialMock(CurrentCompany::class, function ($mock) {
        $mock->shouldReceive('can')->with('sales.view')->andReturn(false);
    });
    $user = salesMember($this->company, CompanyRole::Manager);
    $this->actingAs($user)->get('/app/sales')->assertForbidden();
    $this->actingAs($user)->get('/app/sales/'.R::saleId('300001'))->assertForbidden();
    $this->actingAs($user)->get('/app/sales/export')->assertForbidden();
});

test('the list shows finished sales newest first over the last 7 London days, with names, tenders and refund marks', function () {
    $this->actingAs($this->owner)->get('/app/sales')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('app/sales/index')
        ->where('filters.from', '2026-09-19')->where('filters.to', '2026-09-25')->where('filters.shop', 'all')
        ->where('sales.count', 5)->where('sales.capped', false)->where('sales.newer', null)->where('sales.older', null)
        ->has('sales.data', 5)
        ->where('sales.data.4.receiptNumber', 'LDS-01-300001')->where('sales.data.4.refunded', true)
        ->where('sales.data.4.total', '5.15')->where('sales.data.4.items', 2)->where('sales.data.4.tenders', ['Card'])
        ->where('sales.data.4.shop', 'Leeds')->where('sales.data.4.till', 'Till 1')->where('sales.data.4.day', '2026-09-23')
        ->has('options.shops', 2)->has('options.tills', 3));

    expect(($this->receipts)($this->owner, '?from=2026-09-23&to=2026-09-23'))->toBe(['LDS-01-300001']);
});

test('filters: shop, till, staff, status, payment type, amount, customer and receipt number', function () {
    DB::table('sales')->where('id', R::saleId('300003'))->update(['customer_id' => 'CUST00000000000000000000A1']);
    DB::table('customers')->insert(['id' => 'CUST00000000000000000000A1', 'company_id' => $this->company->id, 'name' => 'Aisha Rahman', 'card_no' => 'LC1']);
    DB::table('sale_payments')->where('sale_id', R::saleId('300002'))->update(['payment_type_name' => 'Loyalty points']);
    DB::table('sales')->where('id', R::saleId('300002'))->update(['total' => '12.00', 'number' => 2002]);
    $r = fn (string $q) => ($this->receipts)($this->owner, $q);

    expect($r('?shop='.T::BRADFORD))->toBe(['LDS-01-300003'])
        ->and($r('?till='.T::TILL_2))->toBe(['LDS-01-300002'])
        ->and($r('?staff='.R::USER_2))->toBe(['LDS-01-300002'])
        ->and($r('?status=refunds'))->toBe(['LDS-01-300004'])
        ->and($r('?status=voided'))->toBe(['LDS-01-300005'])
        ->and($r('?status=completed'))->toBe(['LDS-01-300003', 'LDS-01-300002', 'LDS-01-300001'])
        ->and($r('?payment=loyalty%20POINTS'))->toBe(['LDS-01-300002'])
        ->and($r('?min=10&max=20'))->toBe(['LDS-01-300002'])
        ->and($r('?min=3.70&max=3.70'))->toBe(['LDS-01-300004'])
        ->and($r('?customer=aisha'))->toBe(['LDS-01-300003'])
        ->and($r('?customer=CUST00000000000000000000A1'))->toBe(['LDS-01-300003'])
        ->and($r('?receipt=lds-01-30000'))->toHaveCount(5)
        ->and($r('?receipt=LDS-01-300001&from=2020-01-01&to=2020-01-02'))->toBe(['LDS-01-300001'])
        ->and($r('?receipt=2002'))->toBe(['LDS-01-300002'])
        ->and($r('?receipt=300009'))->toBe([])
        ->and($r('?from=nonsense&status=bogus&shop=x'))->toHaveCount(5);
});

test('keyset paging walks every sale once, older and newer, and counts stop at the cap', function () {
    $older = fn (string $q) => $this->actingAs($this->owner)->get('/app/sales'.$q)->viewData('page')['props']['sales'];

    $first = $older('?perPage=25');
    expect($first['data'])->toHaveCount(5)->and($first['older'])->toBeNull();

    $seen = [];
    $page = app(CurrentCompany::class)->runAs($this->company, fn () => SaleSearch::page(SaleSearch::query(new SaleFilters('2026-09-19', '2026-09-25')), null, null, 2));
    $seen[] = $page['rows']->pluck('receipt_number')->all();
    expect($page['newer'])->toBeNull()->and($page['older'])->not->toBeNull();

    $second = $older('?perPage=25&after='.$page['older']);
    expect(collect($second['data'])->pluck('receiptNumber')->all())->toBe(['LDS-01-300003', 'LDS-01-300002', 'LDS-01-300001'])->and($second['newer'])->not->toBeNull();

    app(CurrentCompany::class)->runAs($this->company, function () use (&$seen) {
        $query = SaleSearch::query(new SaleFilters('2026-09-19', '2026-09-25'));
        $cursor = null;
        $all = [];

        do {
            $page = SaleSearch::page($query, $cursor, null, 2);
            $all = [...$all, ...$page['rows']->pluck('receipt_number')->all()];
            $cursor = $page['older'];
        } while ($cursor !== null);

        expect($all)->toBe(['LDS-01-300005', 'LDS-01-300004', 'LDS-01-300003', 'LDS-01-300002', 'LDS-01-300001']);

        $last = SaleSearch::page($query, SaleSearch::encode(Sale::query()->find(R::saleId('300002'))), null, 2);
        expect($last['rows']->pluck('receipt_number')->all())->toBe(['LDS-01-300001'])->and($last['older'])->toBeNull();
        $back = SaleSearch::page($query, null, $last['newer'], 2);
        expect($back['rows']->pluck('receipt_number')->all())->toBe(['LDS-01-300003', 'LDS-01-300002'])
            ->and($back['newer'])->not->toBeNull()->and($back['older'])->not->toBeNull()
            ->and(SaleSearch::countUpTo($query, 3))->toBe(4);
    });

    $this->actingAs($this->owner)->get('/app/sales?after=garbage')->assertOk();
});

test('a one-shop user sees only their shop, whatever they ask for', function () {
    $bradford = salesMember($this->company, CompanyRole::Manager, T::BRADFORD);

    expect(($this->receipts)($bradford))->toBe(['LDS-01-300003'])
        ->and(($this->receipts)($bradford, '?shop=all'))->toBe(['LDS-01-300003'])
        ->and(($this->receipts)($bradford, '?shop='.T::LEEDS))->toBe(['LDS-01-300003'])
        ->and(($this->receipts)($bradford, '?receipt=LDS-01-300001'))->toBe([]);

    $this->actingAs($bradford)->get('/app/sales')->assertInertia(fn (Assert $page) => $page
        ->where('filters.shopLocked', true)->has('options.shops', 1)->has('options.tills', 1));
    $this->actingAs($bradford)->get('/app/sales/'.R::saleId('300001'))->assertNotFound();
    $this->actingAs($bradford)->get('/app/sales/'.R::saleId('300003'))->assertOk();

    $csv = $this->actingAs($bradford)->get('/app/sales/export?shop=all')->assertOk()->streamedContent();
    expect(substr_count($csv, "\n"))->toBe(2)->and($csv)->toContain('LDS-01-300003')->not->toContain('LDS-01-300001');
});

test('company A never sees company B sales, in the list, the receipt page or the export', function () {
    $otherOwner = salesMember($this->other, CompanyRole::Owner);

    expect(($this->receipts)($otherOwner))->toBe(['LDS-01-300009'])
        ->and(($this->receipts)($otherOwner, '?shop='.T::LEEDS))->toBe([])
        ->and(($this->receipts)($this->owner))->not->toContain('LDS-01-300009');

    $this->actingAs($otherOwner)->get('/app/sales/'.R::saleId('300001'))->assertNotFound();
    $this->actingAs($this->owner)->get('/app/sales/'.R::saleId('300009'))->assertNotFound();

    $csv = $this->actingAs($otherOwner)->get('/app/sales/export')->assertOk()->streamedContent();
    expect($csv)->toContain('LDS-01-300009')->not->toContain('LDS-01-300001');
});
