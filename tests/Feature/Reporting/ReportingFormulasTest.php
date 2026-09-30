<?php

use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Queries\ProductReport;
use App\Domain\Reporting\Queries\SalesReport;
use App\Domain\Reporting\Queries\StaffReport;
use App\Domain\Reporting\Queries\TenderReport;
use App\Domain\Reporting\Queries\VatReport;
use App\Domain\Reporting\ReportTables;
use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Reporting\ReportFixtures;
use Tests\Feature\TillData\TillFixtures;

/*
 * Contract v1.4.1 DASHBOARD.md §6: the worked example, built from the samples through the real apply path.
 */

beforeEach(function () {
    [$this->company, $this->leeds, $this->bradford] = TillFixtures::tenant();
    $this->asCompany = fn (Closure $callback) => app(CurrentCompany::class)->runAs($this->company, $callback);
});

it('builds every rpt row of §6 from push-request.json', function () {
    TillFixtures::apply($this->company, $this->leeds, TillFixtures::sample('push-request.json'));

    expect(ReportFixtures::daily(TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-23'))->toMatchArray([
        'gross' => '5.15', 'net' => '4.53', 'vat' => '0.62', 'txn_count' => '1', 'refund_count' => '0', 'refund_gross' => '0.00',
        'discount' => '0.00', 'cost' => '1.7600', 'container_deposits' => '0.00', 'takings' => '5.15', 'void_count' => '0',
    ]);

    $hourly = DB::table(ReportTables::SALES_HOURLY)->get();
    expect($hourly)->toHaveCount(1)
        ->and((int) $hourly[0]->hour)->toBe(10)
        ->and((int) $hourly[0]->txn_count)->toBe(1);

    $tender = DB::table(ReportTables::TENDER_DAILY)->first();
    expect($tender->payment_type_id)->toBe('01K5T0Q8C4000000000000T001')
        ->and($tender->payment_type_name)->toBe('Card')
        ->and((int) $tender->count)->toBe(1);

    $products = DB::table(ReportTables::PRODUCT_DAILY)->orderBy('product_id')->get();
    expect($products)->toHaveCount(2)
        ->and($products[0]->last_name)->toBe('Warburtons Toastie White Bread 800g');

    ($this->asCompany)(function () {
        $scope = ReportScope::tenant('2026-09-23', '2026-09-23', [TillFixtures::LEEDS]);
        $totals = app(SalesReport::class)->totals($scope);

        expect($totals->gross)->toBe('5.15')
            ->and($totals->net)->toBe('4.53')
            ->and($totals->vat)->toBe('0.62')
            ->and($totals->transactions)->toBe(1)
            ->and($totals->averageBasketExVat())->toBe('4.53')
            ->and($totals->averageBasketIncVat())->toBe('5.15')
            ->and($totals->refundGross)->toBe('0.00')
            ->and($totals->takings)->toBe('5.15')
            ->and($totals->grossProfit())->toBe('2.77');

        $hour = app(SalesReport::class)->byHour($scope)[10];
        expect([$hour->hour, $hour->net, $hour->transactions])->toBe([10, '4.53', 1]);

        $tenders = app(TenderReport::class)->byPaymentType($scope);
        expect($tenders)->toHaveCount(1)
            ->and([$tenders[0]->name, $tenders[0]->amount, $tenders[0]->payments, $tenders[0]->refunds])->toBe(['Card', '5.15', 1, '0.00']);

        $top = app(ProductReport::class)->top($scope);
        expect(array_map(fn ($p) => [$p->name, $p->net, $p->qty], $top))->toBe([
            ['Coca-Cola Original Taste 500ml', '3.08', '2.0000'],
            ['Warburtons Toastie White Bread 800g', '1.45', '1.0000'],
        ]);

        $vat = app(VatReport::class)->byRate($scope);
        expect(array_map(fn ($v) => [$v->code, $v->percentage, $v->net, $v->vat, $v->gross], $vat))->toBe([
            ['S', '20.00', '3.08', '0.62', '3.70'],
            ['Z', '0.00', '1.45', '0.00', '1.45'],
        ]);

        $staff = app(StaffReport::class)->byUser($scope);
        expect($staff)->toHaveCount(1)
            ->and([$staff[0]->userId, $staff[0]->transactions, $staff[0]->net])->toBe([ReportFixtures::USER_1, 1, '4.53']);
    });
});

it('gives the multi-shop table of §6 for the three samples in any order, pushed twice', function () {
    $pushes = [
        fn () => TillFixtures::apply($this->company, $this->bradford, TillFixtures::sample('push-request.second-branch.json')),
        fn () => TillFixtures::apply($this->company, $this->leeds, TillFixtures::sample('push-request.second-till.json')),
        fn () => TillFixtures::apply($this->company, $this->leeds, TillFixtures::sample('push-request.json')),
    ];

    foreach ([...$pushes, ...array_reverse($pushes)] as $push) {
        $push();
    }

    ($this->asCompany)(function () {
        $report = app(SalesReport::class);
        $day = fn (?array $branches = null, ?array $registers = null) => $report->totals(ReportScope::tenant('2026-09-23', '2026-09-23', $branches, $registers));
        $row = fn ($t) => [$t->gross, $t->net, $t->vat, $t->transactions, $t->averageBasketExVat()];

        expect($row($day([TillFixtures::LEEDS], [TillFixtures::TILL_1])))->toBe(['5.15', '4.53', '0.62', 1, '4.53'])
            ->and($row($day([TillFixtures::LEEDS], [TillFixtures::TILL_2])))->toBe(['10.30', '9.06', '1.24', 2, '4.53'])
            ->and($row($day([TillFixtures::LEEDS])))->toBe(['15.45', '13.59', '1.86', 3, '4.53'])
            ->and($row($day([TillFixtures::BRADFORD])))->toBe(['5.15', '4.53', '0.62', 1, '4.53'])
            ->and($row($day()))->toBe(['20.60', '18.12', '2.48', 4, '4.53']);

        $scope = ReportScope::tenant('2026-09-23', '2026-09-23');
        $top = app(ProductReport::class)->top($scope, 5);
        expect(array_map(fn ($p) => [$p->name, $p->qty, $p->net], $top))->toBe([
            ['Coca-Cola Original Taste 500ml', '8.0000', '12.32'],
            ['Warburtons Toastie White Bread 800g', '4.0000', '5.80'],
        ]);

        // Leeds till 2's two sales are the till 2 cashier's.
        $staff = collect(app(StaffReport::class)->byUser($scope))->keyBy('userId');
        expect($staff[ReportFixtures::USER_2]->transactions)->toBe(2)
            ->and($staff[ReportFixtures::USER_1]->transactions)->toBe(2);

        $byShop = app(SalesReport::class)->byBranch($scope);
        expect(array_map(fn ($g) => [$g->label, $g->net], $byShop))->toBe([['Leeds', '13.59'], ['Bradford', '4.53']]);

        $byTill = collect(app(SalesReport::class)->byRegister($scope))->keyBy('id');
        expect($byTill[TillFixtures::TILL_2]->label)->toBe('02 – Till 2');

        // Check 7: takings by tender = takings of the sales, per shop-day.
        $tenders = collect(app(TenderReport::class)->byPaymentType($scope))->map(fn ($t) => $t->amount)->all();
        expect($tenders)->toBe(['20.60'])
            ->and($report->totals($scope)->takings)->toBe('20.60');
    });
});

it('keeps a refund on its own day, negative, shown positive in the refund tiles (§6 illustration)', function () {
    TillFixtures::apply($this->company, $this->leeds, TillFixtures::sample('push-request.json'));
    TillFixtures::apply($this->company, $this->leeds, TillFixtures::sample('push-request.second-till.json'));
    $before = ReportFixtures::snapshot();

    ReportFixtures::push($this->company, $this->leeds, ReportFixtures::basket('900001', '2026-09-25T13:05:00Z', 30001, [
        'type' => 'refund', 'original' => '01K5VB000000000SR001000482',
    ]));

    expect(ReportFixtures::daily(TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-25'))->toMatchArray([
        'gross' => '-3.70', 'net' => '-3.08', 'vat' => '-0.62', 'txn_count' => '0', 'refund_count' => '1',
        'refund_gross' => '3.70', 'refund_net' => '3.08', 'takings' => '-3.70',
    ]);

    // 23 Sep is untouched.
    $after = ReportFixtures::snapshot();
    $only23 = fn (array $s) => array_map(fn ($rows) => array_values(array_filter($rows, fn ($r) => $r['trading_day'] === '2026-09-23')), $s);
    expect($only23($after))->toBe($only23($before));

    ($this->asCompany)(function () {
        $scope = ReportScope::tenant('2026-09-25', '2026-09-25', [TillFixtures::LEEDS]);
        $tender = app(TenderReport::class)->byPaymentType($scope)[0];
        $cola = app(ProductReport::class)->top($scope)[0];

        expect([$tender->amount, $tender->refunds])->toBe(['-3.70', '3.70'])
            ->and([$cola->qty, $cola->refundQty, $cola->net, $cola->refundNet])->toBe(['-2.0000', '2.0000', '-3.08', '3.08'])
            ->and(app(SalesReport::class)->totals(ReportScope::tenant('2026-09-23', '2026-09-23', [TillFixtures::LEEDS]))->net)->toBe('13.59');
    });
});

it('counts nothing for training, quote, open or held sales and counts voids apart', function () {
    ReportFixtures::push($this->company, $this->leeds, [
        ...ReportFixtures::basket('000101', '2026-09-23T10:00:00Z', 100, ['type' => 'training']),
        ...ReportFixtures::basket('000102', '2026-09-23T10:05:00Z', 110, ['type' => 'quote']),
        ...ReportFixtures::basket('000103', '2026-09-23T10:10:00Z', 120, ['status' => 'open']),
        ...ReportFixtures::basket('000104', '2026-09-23T10:15:00Z', 130, ['status' => 'held']),
        ...ReportFixtures::basket('000105', '2026-09-23T10:20:00Z', 140, ['status' => 'voided']),
    ]);

    expect(ReportFixtures::daily(TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-23'))->toMatchArray([
        'gross' => '0.00', 'net' => '0.00', 'txn_count' => '0', 'takings' => '0.00', 'void_count' => '1', 'void_total' => '5.15',
    ])
        ->and(DB::table(ReportTables::SALES_HOURLY)->count())->toBe(0)
        ->and(DB::table(ReportTables::TENDER_DAILY)->count())->toBe(0)
        ->and(DB::table(ReportTables::PRODUCT_DAILY)->count())->toBe(0)
        ->and(DB::table(ReportTables::VAT_DAILY)->count())->toBe(0);
});

it('splits discounts into promotions, coupons and manual, and shows no profit without costs', function () {
    $basket = ReportFixtures::basket('000201', '2026-09-23T11:00:00Z', 200);

    foreach ($basket as &$row) {
        if ($row['entity'] === 'SaleLine') {
            $row['payload']['costAtSale'] = 0;

            if ($row['payload']['productId'] === '01K5T0Q8C4000000000000P002') {
                [$row['payload']['lineDiscount'], $row['payload']['promotionDiscount'], $row['payload']['couponDiscount']] = [0.9, 0.5, 0.1];
            }
        }
    }
    unset($row);

    ReportFixtures::push($this->company, $this->leeds, $basket);

    ($this->asCompany)(function () {
        $totals = app(SalesReport::class)->totals(ReportScope::tenant('2026-09-23', '2026-09-23'));

        expect([$totals->discount, $totals->promo, $totals->coupon, $totals->manualDiscount()])->toBe(['0.90', '0.50', '0.10', '0.30'])
            ->and($totals->grossProfit())->toBeNull()
            ->and(app(SalesReport::class)->totals(ReportScope::tenant('2026-09-24', '2026-09-24'))->averageBasketExVat())->toBeNull();
    });
});
