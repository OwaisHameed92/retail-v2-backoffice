<?php

use App\Domain\Reporting\Dashboard\BusinessDashboard;
use App\Domain\Reporting\Dashboard\BusinessDashboardFilters;
use App\Domain\Reporting\Dashboard\ShopFreshness;
use App\Domain\Reporting\Enums\TradingCompare;
use App\Domain\Reporting\Enums\TradingPeriod;
use App\Domain\Reporting\ReportTables;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Reporting\BusinessDashboardHelpers as H;
use Tests\Feature\Reporting\ReportFixtures;
use Tests\Feature\TillData\TillFixtures;

/*
 * Module 3.3: the business dashboard's figures equal the rpt tables. "Now" is Wednesday 23 Sept 2026, 18:00 London.
 * Kirkgate: today Leeds till 1 × 3, Leeds till 2 × 1 (cashier 2), Bradford × 1; yesterday Leeds × 1 at 11:00.
 */

beforeEach(function () {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-09-23 18:00', 'Europe/London'));
    [$this->kirkgate, $this->leeds, $this->bradford] = TillFixtures::tenant();
    $this->other = Company::factory()->create(['name' => 'Other Stores']);
    $shop = Branch::factory()->forCompany($this->other)->create(['code' => 'OTH', 'name' => 'Other shop']);
    H::basket($this->other, $shop, Register::factory()->forBranch($shop)->create(['code' => '01'])->id, 400009, '2026-09-23T15:00:00Z');

    foreach ([400001, 400002, 400003] as $i => $n) {
        H::basket($this->kirkgate, $this->leeds, TillFixtures::TILL_1, $n, sprintf('2026-09-23T%02d:10:00Z', 8 + $i));
    }
    H::basket($this->kirkgate, $this->leeds, TillFixtures::TILL_2, 400004, '2026-09-23T12:00:00Z', ['user' => ReportFixtures::USER_2]);
    H::basket($this->kirkgate, $this->bradford, TillFixtures::BRADFORD_TILL, 400005, '2026-09-23T13:00:00Z');
    H::basket($this->kirkgate, $this->leeds, TillFixtures::TILL_1, 400006, '2026-09-22T10:00:00Z');

    app(CurrentCompany::class)->set($this->kirkgate, CompanyRole::Owner);
});

function business(string $period = 'today', ?string $branch = null, ?string $till = null, TradingCompare $compare = TradingCompare::PreviousPeriod, ?string $from = null, ?string $to = null): array
{
    $filters = BusinessDashboardFilters::resolve(app(CurrentCompany::class)->require()->id, TradingPeriod::from($period), $from, $to, $compare, $branch, $till);

    return app(BusinessDashboard::class)->compute($filters, CarbonImmutable::now());
}

test('the tiles, charts and lists equal the rpt tables of this business only', function () {
    $data = business();
    $rpt = DB::table(ReportTables::SALES_DAILY)->where('company_id', $this->kirkgate->id)->where('trading_day', '2026-09-23')
        ->selectRaw('SUM(ROUND(net * 100)) as net, SUM(ROUND(gross * 100)) as gross, SUM(ROUND(vat * 100)) as vat, SUM(ROUND(takings * 100)) as takings, SUM(txn_count) as txn')->first();
    $pounds = fn ($pence) => number_format($pence / 100, 2, '.', '');

    expect($data['level'])->toBe('business')
        ->and($data['kpis']['headline']['net']['value'])->toBe($pounds($rpt->net))->toBe('22.65')
        ->and($data['kpis']['headline']['gross']['value'])->toBe($pounds($rpt->gross))
        ->and($data['kpis']['totals']['vat'])->toBe($pounds($rpt->vat))
        ->and($data['kpis']['totals']['takings'])->toBe($pounds($rpt->takings))
        ->and((int) $data['kpis']['headline']['transactions']['value'])->toBe((int) $rpt->txn)
        ->and($data['kpis']['headline']['averageBasket']['value'])->toBe('4.53')
        // Today against yesterday up to 18:00 (the 11:00 basket).
        ->and($data['kpis']['headline']['net'])->toMatchArray(['previous' => '4.53', 'change' => '400.0'])
        ->and($data['range'])->toMatchArray(['isToday' => true, 'today' => '2026-09-23', 'hour' => 18])
        ->and(array_column($data['shops'], 'label'))->toBe(['Leeds', 'Bradford'])
        ->and(array_column($data['shops'], 'net'))->toBe(['18.12', '4.53'])
        ->and($data['tills'])->toBeNull()
        ->and($data['tenders'][0])->toMatchArray(['name' => 'Card', 'amount' => $pounds($rpt->takings), 'payments' => 5])
        ->and(array_column($data['vat'], 'code'))->toBe(['S', 'Z'])
        ->and(array_column($data['products'], 'net'))->toBe(['15.40', '7.25'])
        ->and($data['products'][0]['qty'])->toBe('10.0000')
        ->and(array_column($data['departments'], 'net'))->toBe(['22.65'])
        ->and(array_column($data['staff'], 'net'))->toBe(['18.12', '4.53'])
        ->and($data['staff'][1])->toMatchArray(['userId' => ReportFixtures::USER_2, 'transactions' => 1])
        ->and($data['hourly'][9]['net'])->toBe('4.53')
        ->and($data['hourly'][19]['net'])->toBeNull();
});

test('one shop lists its tills; one till narrows every figure to it', function () {
    $shop = business(branch: TillFixtures::LEEDS);
    expect($shop['level'])->toBe('shop')
        ->and($shop['kpis']['headline']['net']['value'])->toBe('18.12')
        ->and($shop['shops'])->toBeNull()
        ->and(array_column($shop['tills'], 'label'))->toBe(['01 – Till 1', '02 – Till 2']);

    $till = business(branch: TillFixtures::LEEDS, till: TillFixtures::TILL_2);
    expect($till['level'])->toBe('till')
        ->and($till['kpis']['headline']['net']['value'])->toBe('4.53')
        ->and(array_column($till['staff'], 'userId'))->toBe([ReportFixtures::USER_2])
        ->and($till['tills'])->toHaveCount(2);

    // A till without a shop is ignored.
    expect(business(till: TillFixtures::TILL_2)['level'])->toBe('business');
});

test('this week runs Monday to today; a range ending today marks today in the daily series', function () {
    $week = business('thisWeek');

    expect($week['range'])->toMatchArray(['from' => '2026-09-21', 'to' => '2026-09-23', 'today' => '2026-09-23', 'compareFrom' => '2026-09-18', 'compareTo' => '2026-09-20'])
        ->and(array_column($week['daily'], 'day'))->toBe(['2026-09-21', '2026-09-22', '2026-09-23'])
        ->and(array_column($week['daily'], 'net'))->toBe(['0.00', '4.53', '22.65'])
        ->and($week['kpis']['headline']['net']['series'])->toBe([0.0, 4.53, 22.65]);

    $custom = business('custom', from: '2026-09-22', to: '2026-09-22', compare: TradingCompare::None);
    expect($custom['kpis']['headline']['net'])->toMatchArray(['value' => '4.53', 'previous' => null])->and($custom['daily'])->toBeNull();
});

test('cash variance, low stock and orders ready follow the till\'s rules', function () {
    $c = $this->kirkgate->id;
    H::shift($c, TillFixtures::LEEDS, 'SH1', '2026-09-23 16:00:00', '-2.50');     // today, short
    H::shift($c, TillFixtures::BRADFORD, 'SH2', '2026-09-23 22:30:00', '1.00');   // 23:30 London: still today
    H::shift($c, TillFixtures::LEEDS, 'SH3', '2026-09-22 23:30:00', '-9.00');     // 00:30 London on the 23rd: today too
    H::shift($c, TillFixtures::LEEDS, 'SH4', '2026-09-22 22:00:00', '-7.00');     // 23:00 London on the 22nd: not today

    H::stock($c, TillFixtures::LEEDS, 'P1', '5');                 // default threshold 5 → low
    H::stock($c, TillFixtures::LEEDS, 'P2', '6');                 // not low
    H::stock($c, TillFixtures::LEEDS, 'P3', '7', min: '8');       // minStockQty 8 → low
    H::stock($c, TillFixtures::LEEDS, 'P4', '0', tracked: false); // not tracked
    H::stock($c, TillFixtures::BRADFORD, 'P5', '9');              // Bradford's threshold 10 → low
    DB::table('till_settings')->insert(['id' => 'SET1', 'company_id' => $c, 'scope' => 'branch', 'scope_id' => TillFixtures::BRADFORD, 'setting_key' => 'stock.low_stock_threshold', 'value' => '10']);

    foreach (['ready', 'ready', 'collected'] as $i => $status) {
        DB::table('customer_orders')->insert(['id' => "ORD{$i}", 'company_id' => $c, 'branch_id' => TillFixtures::LEEDS, 'status' => $status]);
    }
    DB::table('customer_orders')->insert(['id' => 'ORDX', 'company_id' => $this->other->id, 'branch_id' => TillFixtures::LEEDS, 'status' => 'ready']);

    $ops = business()['operations'];
    expect($ops['cash'])->toBe(['variance' => '-10.50', 'shifts' => 3, 'shortShifts' => 2])
        ->and($ops['lowStock'])->toEqual(['total' => 3, 'byShop' => [TillFixtures::BRADFORD => 1, TillFixtures::LEEDS => 2]])
        ->and($ops['ordersReady'])->toBe(2);

    $leeds = business(branch: TillFixtures::LEEDS)['operations'];
    expect($leeds['lowStock']['total'])->toBe(2)->and($leeds['cash']['shifts'])->toBe(2);
});

test('freshness shows each shop\'s last push and whether there are any figures yet', function () {
    DB::table('sync_branch_status')->insert([
        ['id' => '01K5T0Q8C40000000000SBS001', 'company_id' => $this->kirkgate->id, 'branch_id' => TillFixtures::LEEDS, 'last_push_at' => '2026-09-23 16:59:00', 'last_pull_at' => '2026-09-23 16:59:30'],
        ['id' => '01K5T0Q8C40000000000SBS002', 'company_id' => $this->kirkgate->id, 'branch_id' => TillFixtures::BRADFORD, 'last_push_at' => '2026-09-23 16:00:00', 'last_pull_at' => null],
    ]);
    $filters = BusinessDashboardFilters::resolve($this->kirkgate->id, TradingPeriod::Today, null, null, TradingCompare::PreviousPeriod);
    $fresh = ShopFreshness::for($filters->scope(), CarbonImmutable::now());

    expect($fresh['hasData'])->toBeTrue()
        ->and($fresh['lastPushAt'])->toBe('2026-09-23T16:59:00Z')
        ->and(array_column($fresh['shops'], 'state', 'name'))->toBe(['Bradford' => 'stale', 'Leeds' => 'live']);

    $empty = Company::factory()->create();
    app(CurrentCompany::class)->set($empty, CompanyRole::Owner);
    $none = BusinessDashboardFilters::resolve($empty->id, TradingPeriod::Today, null, null, TradingCompare::PreviousPeriod);
    expect(ShopFreshness::for($none->scope(), CarbonImmutable::now()))->toMatchArray(['hasData' => false, 'lastPushAt' => null, 'shops' => []]);
});

test('figures are cached per business and filter set, and the query count is fixed', function () {
    $filters = BusinessDashboardFilters::resolve($this->kirkgate->id, TradingPeriod::Last7Days, null, null, TradingCompare::PreviousPeriod);
    $other = BusinessDashboardFilters::resolve($this->other->id, TradingPeriod::Last7Days, null, null, TradingCompare::PreviousPeriod);
    expect($filters->cacheKey())->not->toBe($other->cacheKey());

    DB::flushQueryLog();
    DB::enableQueryLog();
    app(BusinessDashboard::class)->for($filters);
    $queries = count(DB::getQueryLog());
    app(BusinessDashboard::class)->for($filters);
    $cached = count(DB::getQueryLog()) - $queries;
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(24)->and($cached)->toBeLessThanOrEqual(1);
});
