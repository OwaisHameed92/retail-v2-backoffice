<?php

use App\Domain\Admin\Data\TradingFilters;
use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Enums\TradingCompare;
use App\Domain\Admin\Enums\TradingPeriod;
use App\Domain\Admin\Models\Admin;
use App\Domain\Admin\Queries\Trading\TradingDashboard;
use App\Domain\Reporting\ReportTables;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Reporting\ReportFixtures;
use Tests\Feature\TillData\TillFixtures;

/*
 * Module 3.2: the admin dashboard's Trading tab. Every basket is the sample's (net £4.53, gross £5.15, VAT £0.62,
 * card £5.15). "Now" is Wednesday 23 Sept 2026, 18:00 London.
 */

function tradingBasket(Company $company, Branch $branch, string $till, int $n, string $at, array $o = []): void
{
    $rows = ReportFixtures::basket((string) $n, $at, $n * 10, ['register' => $till, 'branch' => $branch->id] + $o);
    $rows = json_decode(str_replace(TillFixtures::COMPANY, $company->id, (string) json_encode($rows)), true);
    ReportFixtures::push($company, $branch, $rows);
}

function trading(string $period = 'today', ?string $company = null, ?string $branch = null, TradingCompare $compare = TradingCompare::PreviousPeriod, ?string $from = null, ?string $to = null): array
{
    return app(TradingDashboard::class)->compute(
        TradingFilters::resolve(TradingPeriod::from($period), $from, $to, $compare, $company, $branch),
        CarbonImmutable::now(),
    );
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-23 18:00', 'Europe/London'));
    [$this->kirkgate, $this->leeds, $this->bradford] = TillFixtures::tenant();
    $this->other = Company::factory()->create(['name' => 'Other Stores']);
    $this->otherShop = Branch::factory()->forCompany($this->other)->create(['code' => 'OTH', 'name' => 'Other shop']);
    $this->otherTill = Register::factory()->forBranch($this->otherShop)->create(['code' => '01', 'is_main_till' => true]);

    foreach ([100001, 100002, 100003] as $i => $n) {
        tradingBasket($this->kirkgate, $this->leeds, TillFixtures::TILL_1, $n, sprintf('2026-09-23T%02d:10:00Z', 8 + $i));
    }

    tradingBasket($this->kirkgate, $this->leeds, TillFixtures::TILL_2, 100004, '2026-09-23T12:00:00Z');
    tradingBasket($this->kirkgate, $this->bradford, TillFixtures::BRADFORD_TILL, 100005, '2026-09-23T13:00:00Z');
    tradingBasket($this->kirkgate, $this->leeds, TillFixtures::TILL_1, 100006, '2026-09-22T10:00:00Z');
    tradingBasket($this->kirkgate, $this->leeds, TillFixtures::TILL_1, 100007, '2026-09-22T19:30:00Z');
    tradingBasket($this->other, $this->otherShop, $this->otherTill->id, 100008, '2026-09-23T15:00:00Z');
});

test('guests and customers are sent to the admin login; sales staff get 403; owner, support and accounts get in', function () {
    $this->get('/admin/trading')->assertRedirect(route('admin.login'));
    $this->getJson('/admin/trading')->assertUnauthorized();

    $customer = User::factory()->withCompany($this->kirkgate)->create();
    $this->actingAs($customer)->get('/admin/trading')->assertRedirect(route('admin.login'));

    $this->actingAs(Admin::factory()->role(AdminRole::Sales)->create(), 'admin')->get('/admin/trading')->assertForbidden();
    $this->actingAs(Admin::factory()->role(AdminRole::Sales)->create(), 'admin')->get('/admin/trading?company='.$this->kirkgate->id)->assertForbidden();

    foreach ([AdminRole::Owner, AdminRole::Support, AdminRole::Accounts] as $role) {
        $this->actingAs(Admin::factory()->role($role)->create(), 'admin')->get('/admin/trading')->assertOk();
    }

    expect(AdminRole::Sales->can(AdminRole::TRADING_VIEW))->toBeFalse();
});

test('the page paints the filters first and loads the figures as a deferred prop', function () {
    $this->actingAs(Admin::factory()->create(), 'admin')
        ->get('/admin/trading?period=last7Days&compare=sameLastWeek&company='.$this->kirkgate->id.'&branch='.TillFixtures::LEEDS)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/trading')
            ->where('filters', ['period' => 'last7Days', 'from' => '2026-09-17', 'to' => '2026-09-23', 'compare' => 'sameLastWeek', 'company' => $this->kirkgate->id, 'branch' => TillFixtures::LEEDS, 'today' => '2026-09-23'])
            ->where('context.company.name', 'Kirkgate Convenience')
            ->where('context.branch.name', 'Leeds')
            ->has('context.branches', 2)
            ->has('periods', 7)
            ->missing('trading')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('trading.level', 'shop')
                ->where('trading.kpis.headline.net.value', '27.18')
                ->has('trading.leaders.tills', 2)));
});

test('across every business the tiles equal the rpt tables, with top businesses and shops', function () {
    $data = trading();
    $rpt = DB::table(ReportTables::SALES_DAILY)->where('trading_day', '2026-09-23')
        ->selectRaw('SUM(ROUND(net * 100)) as net, SUM(ROUND(gross * 100)) as gross, SUM(txn_count) as txn')->first();

    expect($data['kpis']['headline']['net']['value'])->toBe(number_format($rpt->net / 100, 2, '.', ''))
        ->and($data['kpis']['headline']['gross']['value'])->toBe(number_format($rpt->gross / 100, 2, '.', ''))
        ->and((int) $data['kpis']['headline']['transactions']['value'])->toBe((int) $rpt->txn)
        ->and($data['kpis']['headline']['net']['value'])->toBe('27.18')
        ->and($data['kpis']['headline']['averageBasket']['value'])->toBe('4.53')
        ->and($data['kpis']['totals']['vat'])->toBe('3.72')
        ->and($data['kpis']['totals']['takings'])->toBe('30.90')
        ->and($data['activity'])->toMatchArray(['companies' => 2, 'branches' => 3, 'registers' => 4, 'pendingDays' => 0])
        ->and(array_column($data['leaders']['businesses'], 'label'))->toBe(['Kirkgate Convenience', 'Other Stores'])
        ->and($data['leaders']['businesses'][0])->toMatchArray(['net' => '22.65', 'transactions' => 5, 'children' => 2])
        ->and($data['leaders']['shops'][0])->toMatchArray(['label' => 'Leeds', 'parentLabel' => 'Kirkgate Convenience', 'net' => '18.12', 'children' => 2])
        ->and($data['leaders']['products'])->toBeNull()
        ->and(array_column($data['tenders'], 'name'))->toBe(['Card'])
        ->and($data['tenders'][0]['amount'])->toBe('30.90')
        ->and(array_column($data['vat'], 'code'))->toBe(['S', 'Z']);
});

test('today compares with yesterday up to the same hour, and hourly points stop at the current hour', function () {
    $data = trading();

    // Yesterday up to 18:00 London holds the 11:00 basket only (the 20:30 one is later in the day).
    expect($data['kpis']['headline']['net'])->toMatchArray(['value' => '27.18', 'previous' => '4.53', 'change' => '500.0'])
        ->and($data['kpis']['changes'])->toBeNull()
        ->and($data['range'])->toMatchArray(['isToday' => true, 'hour' => 18, 'compareFrom' => '2026-09-22', 'compareTo' => '2026-09-22'])
        ->and($data['daily'])->toBeNull()
        ->and($data['hourly'][9]['net'])->toBe('4.53')
        ->and($data['hourly'][19]['net'])->toBeNull()
        ->and($data['hourly'][20]['compareNet'])->toBe('4.53');
});

test('a range compares each day with the previous period, secondary tiles included', function () {
    $data = trading('custom', from: '2026-09-23', to: '2026-09-22');   // swapped back into order

    expect($data['range'])->toMatchArray(['from' => '2026-09-22', 'to' => '2026-09-23', 'days' => 2, 'compareFrom' => '2026-09-20', 'compareTo' => '2026-09-21'])
        ->and($data['kpis']['headline']['net'])->toMatchArray(['value' => '36.24', 'previous' => '0.00', 'change' => null])
        ->and($data['kpis']['changes'])->toMatchArray(['takings' => null])
        ->and(array_column($data['daily'], 'net'))->toBe(['9.06', '27.18'])
        ->and($data['kpis']['headline']['net']['series'])->toBe([9.06, 27.18]);

    $none = trading('yesterday', compare: TradingCompare::None);
    expect($none['kpis']['headline']['net'])->toMatchArray(['value' => '9.06', 'previous' => null, 'change' => null])
        ->and($none['range']['compareFrom'])->toBeNull();
});

test('drilling into a business or a shop shows only its figures, its shops or tills, and its top products', function () {
    $business = trading(company: $this->other->id);
    expect($business['level'])->toBe('business')
        ->and($business['kpis']['headline']['net']['value'])->toBe('4.53')
        ->and(array_column($business['leaders']['shops'], 'label'))->toBe(['Other shop'])
        ->and($business['leaders']['businesses'])->toBeNull()
        ->and(array_column($business['leaders']['products'], 'name'))->toBe(['Coca-Cola Original Taste 500ml', 'Warburtons Toastie White Bread 800g']);

    $shop = trading(company: $this->kirkgate->id, branch: TillFixtures::LEEDS);
    expect($shop['level'])->toBe('shop')
        ->and($shop['kpis']['headline']['transactions']['value'])->toBe('4')
        ->and(array_column($shop['leaders']['tills'], 'label'))->toBe(['01 – Till 1', '02 – Till 2']);
});

test('an unknown business is a 404 and another business\'s shop is ignored', function () {
    $admin = Admin::factory()->create();

    $this->actingAs($admin, 'admin')->get('/admin/trading?company=01K5T0Q8C4000000000000Z999')->assertNotFound();
    $this->actingAs($admin, 'admin')->get('/admin/trading?company='.$this->other->id.'&branch='.TillFixtures::LEEDS)
        ->assertInertia(fn (Assert $page) => $page->where('filters.branch', null)->where('context.branch', null)
            ->loadDeferredProps(fn (Assert $reload) => $reload->where('trading.kpis.headline.net.value', '4.53')));

    // Junk in the query string never fails the page.
    $this->actingAs($admin, 'admin')->get('/admin/trading?period=forever&compare=x&company=not-an-id&from=2026-02-31')
        ->assertInertia(fn (Assert $page) => $page->where('filters.period', 'today')->where('filters.compare', 'previousPeriod')->where('filters.company', null));
});

test('presets resolve to London trading days and a custom range is clamped', function () {
    $f = fn (TradingPeriod $p, ?string $from = null, ?string $to = null) => TradingFilters::resolve($p, $from, $to, TradingCompare::PreviousPeriod)->toArray();

    expect($f(TradingPeriod::Today))->toMatchArray(['from' => '2026-09-23', 'to' => '2026-09-23'])
        ->and($f(TradingPeriod::Yesterday))->toMatchArray(['from' => '2026-09-22', 'to' => '2026-09-22'])
        ->and($f(TradingPeriod::Last7Days))->toMatchArray(['from' => '2026-09-17', 'to' => '2026-09-23'])
        ->and($f(TradingPeriod::Last30Days))->toMatchArray(['from' => '2026-08-25', 'to' => '2026-09-23'])
        ->and($f(TradingPeriod::ThisMonth))->toMatchArray(['from' => '2026-09-01', 'to' => '2026-09-23'])
        ->and($f(TradingPeriod::LastMonth))->toMatchArray(['from' => '2026-08-01', 'to' => '2026-08-31'])
        ->and($f(TradingPeriod::Custom, '2026-09-20', '2027-01-01'))->toMatchArray(['period' => 'custom', 'from' => '2026-09-20', 'to' => '2026-09-23'])
        ->and($f(TradingPeriod::Custom, '2020-01-01', '2026-09-23'))->toMatchArray(['from' => '2025-09-23', 'to' => '2026-09-23'])
        ->and($f(TradingPeriod::Custom, 'nonsense', null))->toMatchArray(['period' => 'last7Days', 'from' => '2026-09-17']);

    // 23:30 UTC on a summer day is already tomorrow in London.
    $this->travelTo(CarbonImmutable::parse('2026-09-23 23:30', 'UTC'));
    expect($f(TradingPeriod::Today))->toMatchArray(['from' => '2026-09-24']);
});

test('figures are cached for a minute per filter set', function () {
    $filters = TradingFilters::resolve(TradingPeriod::Today, null, null, TradingCompare::PreviousPeriod);
    $first = app(TradingDashboard::class)->for($filters);
    DB::table(ReportTables::SALES_DAILY)->where('trading_day', '2026-09-23')->update(['net' => 0]);

    expect(app(TradingDashboard::class)->for($filters)['kpis']['headline']['net']['value'])->toBe($first['kpis']['headline']['net']['value']);

    $this->travel(TradingDashboard::CACHE_SECONDS + 1)->seconds();
    expect(app(TradingDashboard::class)->for($filters)['kpis']['headline']['net']['value'])->toBe('0.00');
    Cache::flush();
});

test('the number of queries does not grow with the number of businesses', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        trading('last7Days');
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $small = $count();

    foreach (range(1, 6) as $i) {
        $company = Company::factory()->create(['name' => "Extra {$i}"]);
        $shop = Branch::factory()->forCompany($company)->create(['code' => 'EXT', 'name' => "Extra shop {$i}"]);
        $till = Register::factory()->forBranch($shop)->create(['code' => '01', 'is_main_till' => true]);
        tradingBasket($company, $shop, $till->id, 200000 + $i, '2026-09-21T12:00:00Z');
    }

    expect($count())->toBe($small)->and($small)->toBeLessThanOrEqual(16);
});
