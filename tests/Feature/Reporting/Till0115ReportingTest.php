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
 * Till 0.1.15 (PORTAL-CHANGES-0.1.15 items 3, 4, 5, 9, 10): order deposits and charity round-ups are not sales, the
 * "Order deposit" and "Loyalty points" tenders are in the tender mix (one line per name), and the staff-purchase
 * discount is reported apart from manual discounts. Incrementally kept rows still equal a rebuild.
 */

beforeEach(function () {
    [$this->company, $this->leeds, $this->bradford] = TillFixtures::tenant();
    $this->asCompany = fn (Closure $callback) => app(CurrentCompany::class)->runAs($this->company, $callback);

    /**
     * A basket with its rows changed: $line(payload of the Warburtons line N1 / Cola line N2), $pay(payment payload),
     * $sale(sale payload); $drop = entity ids to leave out.
     */
    $this->basket = function (string $number, string $at, int $seq, array $options = [], ?Closure $edit = null): array {
        $rows = ReportFixtures::basket($number, $at, $seq, $options);

        if ($edit !== null) {
            $rows = array_values(array_filter(array_map(fn (array $row) => $edit($row, $number), $rows)));
        }

        return $rows;
    };
    $this->payWith = fn (string $typeId, string $name) => function (array $row) use ($typeId, $name): array {
        if ($row['entity'] === 'SalePayment') {
            [$row['payload']['paymentTypeId'], $row['payload']['paymentTypeName']] = [$typeId, $name];
        }

        return $row;
    };
});

function till0115Day(Closure $basket, Closure $payWith): array
{
    $n1 = fn (string $number) => "01K5VB0000000SN1R001{$number}";

    // An order deposit: a `deposit` sale of one ORDER-DEPOSIT line (out of scope, vat 0) paid by card.
    $deposit = $basket('000301', '2026-09-23T11:00:00Z', 300, ['type' => 'deposit'], function (array $row, string $number) use ($n1) {
        $p = &$row['payload'];

        if (in_array($row['entityId'], ["01K5VB0000000SN2R001{$number}", "01K5VB0000000SVSR001{$number}"], true)) {
            return null;
        }

        if ($row['entityId'] === $n1($number)) {
            [$p['productId'], $p['name']] = ['ORDER-DEPOSIT', 'Deposit for order CO-1-1-7'];
        }

        if ($row['entity'] === 'Sale') {
            [$p['subtotal'], $p['total'], $p['vatTotal']] = [1.45, 1.45, 0];
        }

        if ($row['entity'] === 'SalePayment') {
            $p['amount'] = $p['appliedAmount'] = 1.45;
        }
        unset($p);

        return $row;
    });

    // A sale with a charity round-up line (the Warburtons line becomes CHARITY-ROUNDUP, £1.45).
    $charity = $basket('000302', '2026-09-23T12:00:00Z', 310, [], function (array $row, string $number) use ($n1) {
        if ($row['entityId'] === $n1($number)) {
            [$row['payload']['productId'], $row['payload']['name'], $row['payload']['isCharityRoundUp']] = ['CHARITY-ROUNDUP', 'Charity round-up', true];
        }

        return $row;
    });

    // The order collected: the cola paid with the "Order deposit" tender; another cola paid with loyalty points.
    $collection = $basket('000303', '2026-09-23T13:00:00Z', 320, ['colaOnly' => true], $payWith('01K5T0Q8C4000000000000T006', 'Order deposit'));
    $points = $basket('000304', '2026-09-23T14:00:00Z', 330, ['colaOnly' => true], $payWith('01K5T0Q8C4000000000000T007', 'Loyalty points'));

    // A staff purchase: 0.40 staff discount on the cola, 0.20 manual on the bread.
    $staff = $basket('000305', '2026-09-23T15:00:00Z', 340, [], function (array $row, string $number) use ($n1) {
        if ($row['entity'] === 'SaleLine') {
            $staffLine = $row['entityId'] !== $n1($number);
            [$row['payload']['lineDiscount'], $row['payload']['discountSource']] = $staffLine ? [0.4, 'staff'] : [0.2, 'manual'];
        }

        return $row;
    });

    return [...$deposit, ...$charity, ...$collection, ...$points, ...$staff];
}

it('leaves order deposits and charity round-ups out of every sales figure and shows them on their own', function () {
    $result = ReportFixtures::push($this->company, $this->leeds, till0115Day($this->basket, $this->payWith));
    expect($result->rejected)->toBe([]);

    expect(ReportFixtures::daily(TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-23'))->toMatchArray([
        'gross' => '16.25', 'net' => '13.77', 'vat' => '2.48', 'txn_count' => '4', 'takings' => '19.15',
        'order_deposits' => '1.45', 'charity' => '1.45', 'discount' => '0.60', 'staff_discount' => '0.40',
    ]);

    // No product, VAT or hour row is left for the deposit and charity lines (the deposit-only hour is 12:00 local).
    expect(DB::table(ReportTables::PRODUCT_DAILY)->whereIn('product_id', ['ORDER-DEPOSIT', 'CHARITY-ROUNDUP'])->count())->toBe(0)
        ->and(DB::table(ReportTables::SALES_HOURLY)->pluck('hour')->map(fn ($h) => (int) $h)->sort()->values()->all())->toBe([13, 14, 15, 16]);

    ($this->asCompany)(function () {
        $scope = ReportScope::tenant('2026-09-23', '2026-09-23', [TillFixtures::LEEDS]);
        $totals = app(SalesReport::class)->totals($scope);

        expect([$totals->gross, $totals->net, $totals->vat, $totals->transactions])->toBe(['16.25', '13.77', '2.48', 4])
            ->and([$totals->orderDeposits, $totals->charity, $totals->takings])->toBe(['1.45', '1.45', '19.15'])
            ->and([$totals->discount, $totals->staffDiscount, $totals->manualDiscount()])->toBe(['0.60', '0.40', '0.20'])
            ->and($totals->toArray())->toHaveKeys(['staffDiscount', 'orderDeposits', 'charity']);

        $vat = app(VatReport::class)->byRate($scope);
        expect(array_map(fn ($v) => [$v->code, $v->net, $v->vat, $v->gross], $vat))->toBe([
            ['S', '12.32', '2.48', '14.80'],
            ['Z', '1.45', '0.00', '1.45'],
        ]);

        $names = array_map(fn ($p) => $p->name, app(ProductReport::class)->top($scope, 20));
        expect($names)->not->toContain('Deposit for order CO-1-1-7')->not->toContain('Charity round-up');

        $tenders = app(TenderReport::class)->byPaymentType($scope);
        expect(array_map(fn ($t) => [$t->name, $t->amount, $t->payments], $tenders))->toBe([
            ['Card', '11.75', 3],
            ['Loyalty points', '3.70', 1],
            ['Order deposit', '3.70', 1],
        ]);

        $staff = app(StaffReport::class)->byUser($scope);
        expect($staff)->toHaveCount(1)->and($staff[0]->gross)->toBe('16.25');
    });

    $incremental = ReportFixtures::snapshot();
    $this->artisan('reports:check')->assertSuccessful();
    $this->artisan('reports:rebuild')->assertSuccessful();
    expect(ReportFixtures::snapshot())->toBe($incremental);
});

it('adds up same-named payment types of several shops into one tender line', function () {
    ReportFixtures::push($this->company, $this->leeds, ($this->basket)('000401', '2026-09-23T13:00:00Z', 400, ['colaOnly' => true], ($this->payWith)('01K5T0Q8C4000000000000T006', 'Order deposit')));
    ReportFixtures::push($this->company, $this->bradford, ($this->basket)('000402', '2026-09-23T13:00:00Z', 410, ['colaOnly' => true, 'branch' => TillFixtures::BRADFORD, 'register' => TillFixtures::BRADFORD_TILL], ($this->payWith)('01K5T0Q8C4000000000000T106', 'Order deposit')));

    ($this->asCompany)(function () {
        $tenders = app(TenderReport::class)->byPaymentType(ReportScope::tenant('2026-09-23', '2026-09-23'));

        expect(array_map(fn ($t) => [$t->name, $t->amount, $t->payments], $tenders))->toBe([['Order deposit', '7.40', 2]]);
    });

    $this->artisan('reports:check')->assertSuccessful();
});
