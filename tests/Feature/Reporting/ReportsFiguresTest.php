<?php

use App\Domain\Reporting\Actions\BuildReport;
use App\Domain\Reporting\Dashboard\BusinessDashboardFilters;
use App\Domain\Reporting\Enums\TradingCompare;
use App\Domain\Reporting\Enums\TradingPeriod;
use App\Domain\Reporting\Reports\ReportCsv;
use App\Domain\Reporting\Reports\ReportGrouping;
use App\Domain\Reporting\Reports\ReportKind;
use App\Domain\Reporting\Reports\ReportOptions;
use App\Domain\Reporting\Reports\ReportResult;
use App\Domain\Reporting\Reports\ReportTable;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Reporting\BusinessDashboardHelpers as H;
use Tests\Feature\Reporting\ReportFixtures;
use Tests\Feature\Reporting\ReportsHelpers as R;
use Tests\Feature\TillData\TillFixtures;

/*
 * Module 4.8: every sales figure is the rpt_* tables' own sum. "Now" is Wed 23 Sept 2026 18:00 London. Kirkgate:
 * Mon 21 two baskets at Leeds (till 1, user 1), Tue 22 one at Leeds till 2 by user 2 and one refund, Wed 23 one at
 * Bradford; the week before (14–16 Sept) one basket a day at Leeds, for the compare window.
 */

beforeEach(function () {
    Cache::flush();
    $this->travelTo(CarbonImmutable::parse('2026-09-23 18:00', 'Europe/London'));
    [$this->kirkgate, $this->leeds, $this->bradford] = TillFixtures::tenant();
    $b = fn ($branch, $till, $n, $at, $o = []) => H::basket($this->kirkgate, $branch, $till, $n, $at, $o);

    $b($this->leeds, TillFixtures::TILL_1, 500001, '2026-09-21T08:10:00Z');
    $b($this->leeds, TillFixtures::TILL_1, 500002, '2026-09-21T15:40:00Z');
    $b($this->leeds, TillFixtures::TILL_2, 500003, '2026-09-22T12:05:00Z', ['user' => ReportFixtures::USER_2]);
    $b($this->leeds, TillFixtures::TILL_2, 500004, '2026-09-22T13:05:00Z', ['type' => 'refund', 'user' => ReportFixtures::USER_2]);
    $b($this->bradford, TillFixtures::BRADFORD_TILL, 500005, '2026-09-23T10:30:00Z');

    foreach (['14', '15', '16'] as $i => $day) {
        $b($this->leeds, TillFixtures::TILL_1, 500010 + $i, "2026-09-{$day}T09:00:00Z");
    }

    $this->owner = R::member($this->kirkgate, CompanyRole::Owner);
    $this->range = 'period=custom&from=2026-09-21&to=2026-09-23';
});

test('the sales summary equals rpt_sales_daily: headline, by day, by shop, by till, and the compare window', function () {
    $props = R::props($this->actingAs($this->owner)->get("/app/reports/sales?{$this->range}"));
    $id = $this->kirkgate->id;
    $sum = fn (string $col) => DB::table('rpt_sales_daily')->where('company_id', $id)->whereBetween('trading_day', ['2026-09-21', '2026-09-23'])
        ->selectRaw("SUM(ROUND({$col} * 100)) as u")->value('u');
    $money = fn ($units) => bcdiv((string) (int) round((float) $units), '100', 2);

    foreach (['net', 'gross', 'vat', 'takings'] as $col) {
        expect(R::figure($props, $col)['value'])->toBe($money($sum($col)));
    }
    expect(R::figure($props, 'transactions')['value'])->toBe(4)
        ->and(R::figure($props, 'refunds')['value'])->toBe($money($sum('refund_gross')));

    $days = R::table($props, 'periods');
    expect(array_column($days['rows'], 'label'))->toBe(['Mon 21 Sep 2026', 'Tue 22 Sep 2026', 'Wed 23 Sep 2026'])
        ->and($days['rows'][0]['net'])->toBe('9.06')
        ->and($days['totals']['net'])->toBe(R::figure($props, 'net')['value'])
        ->and(array_column(R::table($props, 'shops')['rows'], 'label'))->toBe(['Leeds', 'Bradford'])
        ->and(R::table($props, 'tills')['rows'])->toHaveCount(3);

    // Previous period = 18–20 Sept (nothing); same days last week = 14–16 Sept (3 baskets).
    expect(R::figure($props, 'net')['previous'])->toBe('0.00')->and(R::figure($props, 'net')['change'])->toBeNull();
    $week = R::props($this->actingAs($this->owner)->get("/app/reports/sales?{$this->range}&compare=sameLastWeek"));
    expect(R::figure($week, 'net')['previous'])->toBe('13.59')
        ->and($week['result']['chart']['points'][0]['compare'])->toBe('4.53');

    // By week and by month fold the same days.
    $month = R::props($this->actingAs($this->owner)->get('/app/reports/sales?period=custom&from=2026-09-14&to=2026-09-23&group=week'));
    expect(array_column(R::table($month, 'periods')['rows'], 'label'))->toBe(['w/c 14 Sep 2026', 'w/c 21 Sep 2026'])
        ->and(R::table($month, 'periods')['rows'][0]['net'])->toBe('13.59');
});

test('products, VAT, payments, staff, discounts and busy hours equal their rpt tables', function () {
    $id = $this->kirkgate->id;
    DB::table('rpt_product_daily')->where('company_id', $id)->where('trading_day', '<', '2026-09-21')->delete();
    DB::table('rpt_vat_daily')->where('company_id', $id)->where('trading_day', '<', '2026-09-21')->delete();
    DB::table('rpt_tender_daily')->where('company_id', $id)->where('trading_day', '<', '2026-09-21')->delete();
    DB::table('rpt_sales_hourly')->where('company_id', $id)->where('trading_day', '<', '2026-09-21')->delete();
    DB::table('rpt_staff_daily')->where('company_id', $id)->where('trading_day', '<', '2026-09-21')->delete();
    DB::table('rpt_sales_daily')->where('company_id', $id)->where('trading_day', '<', '2026-09-21')->delete();
    $get = fn (string $report) => R::props($this->actingAs($this->owner)->get("/app/reports/{$report}?{$this->range}"));

    $products = $get('products');
    expect(R::figure($products, 'net')['value'])->toBe(R::sum('rpt_product_daily', 'net', $id))
        ->and(R::table($products, 'products')['totals']['net'])->toBe(R::sum('rpt_product_daily', 'net', $id))
        ->and(R::table($products, 'products')['totals']['cost'])->toBe(bcadd(R::sum('rpt_product_daily', 'cost', $id, null, 4), '0', 2));
    $cost = R::sum('rpt_product_daily', 'cost', $id, null, 2);
    if (bccomp($cost, '0', 2) !== 0) {
        expect(R::figure($products, 'profit')['value'])->toBe(bcsub(R::sum('rpt_product_daily', 'net', $id), $cost, 2));
    }

    $vat = $get('vat');
    expect(R::figure($vat, 'box1')['value'])->toBe(R::sum('rpt_vat_daily', 'vat', $id))
        ->and(R::figure($vat, 'box6')['value'])->toBe(R::sum('rpt_vat_daily', 'net', $id))
        ->and(R::table($vat, 'periods')['totals']['vat'])->toBe(R::sum('rpt_vat_daily', 'vat', $id));

    $tenders = $get('tenders');
    expect(R::figure($tenders, 'amount')['value'])->toBe(R::sum('rpt_tender_daily', 'amount', $id))
        ->and(R::figure($tenders, 'refunds')['value'])->toBe(R::sum('rpt_tender_daily', 'refunds', $id));

    $staff = $get('staff');
    expect(R::table($staff, 'staff')['totals']['net'])->toBe(R::sum('rpt_staff_daily', 'net', $id))
        ->and(R::table($staff, 'staff')['rows'])->toHaveCount(2);

    $discounts = $get('discounts');
    expect(R::figure($discounts, 'discount')['value'])->toBe(R::sum('rpt_sales_daily', 'discount', $id))
        ->and(R::table($discounts, 'sources')['totals']['amount'])->toBe(R::sum('rpt_sales_daily', 'discount', $id));

    $hourly = $get('hourly');
    expect(R::figure($hourly, 'net')['value'])->toBe(R::sum('rpt_sales_hourly', 'net', $id))
        ->and(R::figure($hourly, 'transactions')['value'])->toBe((int) DB::table('rpt_sales_hourly')->where('company_id', $id)->sum('txn_count'))
        ->and($hourly['result']['chart']['type'])->toBe('heatmap')
        ->and(collect($hourly['result']['chart']['rows'])->firstWhere('label', 'Mon')['days'])->toBe(1);

    $refunds = $get('refunds');
    expect(R::figure($refunds, 'refundCount')['value'])->toBe(1)
        ->and(R::figure($refunds, 'refundGross')['value'])->toBe(R::sum('rpt_sales_daily', 'refund_gross', $id))
        ->and(R::table($refunds, 'staff')['rows'][0]['refundCount'])->toBe(1)
        ->and(R::table($refunds, 'products')['rows'])->not->toBeEmpty();
});

test('the CSV holds the heading, figures and every table row, with numbers plain and formulas defused', function () {
    $response = $this->actingAs($this->owner)->get("/app/reports/sales/export?{$this->range}&group=week");
    $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertDownload('sales-summary-2026-09-21-to-2026-09-23.csv');
    $csv = $response->streamedContent();

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->toContain('Report,"Sales summary (by week)"')
        ->toContain('Business,"Kirkgate Convenience"')
        ->toContain('Dates,"21 Sep 2026 – 23 Sep 2026"')
        ->toContain('"Net sales",'.R::figure(R::props($this->actingAs($this->owner)->get("/app/reports/sales?{$this->range}")), 'net')['value'].',0.00,')
        ->toContain('"w/c 21 Sep 2026",4,')
        ->toContain('Total,4,');

    // A name a spreadsheet would run as a formula is quoted.
    $table = new ReportTable('t', 'T', [ReportTable::col('name', 'Name'), ReportTable::col('v', 'V', 'signedMoney')], [['name' => '=HYPERLINK("x")', 'v' => '-1.50']]);
    $lines = ReportCsv::lines(new ReportResult([], [$table]), []);
    expect(end($lines))->toBe(['\'=HYPERLINK("x")', '-1.50']);
});

test('the action pages long lists on screen and returns every row for export', function () {
    app(CurrentCompany::class)->set($this->kirkgate);
    $window = BusinessDashboardFilters::resolve($this->kirkgate->id, TradingPeriod::Custom, '2026-09-14', '2026-09-23', TradingCompare::None);
    $screen = app(BuildReport::class)->handle(ReportKind::Products, new ReportOptions($window, ReportGrouping::Day, '', 1));
    $export = app(BuildReport::class)->handle(ReportKind::Products, new ReportOptions($window, ReportGrouping::Day, '', 1, true));

    expect($screen->table('products')->pagination)->toMatchArray(['page' => 1, 'perPage' => ReportOptions::PAGE_SIZE])
        ->and($export->table('products')->pagination)->toBeNull()
        ->and(count($export->table('products')->rows))->toBe($screen->table('products')->pagination['total']);
});
