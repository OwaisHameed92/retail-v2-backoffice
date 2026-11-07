<?php

use App\Domain\Accounts\Data\AccountsFilters;
use App\Domain\Accounts\Data\VatQuarter;
use App\Domain\Accounts\Queries\VatReturnHelper;
use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Queries\OperationsReport;
use App\Domain\Reporting\Queries\ProductReport;
use App\Domain\Reporting\Queries\SalesReport;
use App\Domain\Reporting\Queries\TenderReport;
use App\Domain\Reporting\Queries\VatReport;
use App\Domain\Reporting\ReportTables;
use App\Domain\Sales\Queries\SaleReceipt;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Reporting\ReportFixtures;
use Tests\Feature\Sync\Till0185Rows as Rows;
use Tests\Feature\TillData\TillFixtures;

/*
 * Till 0.1.85 money (PORTAL-CHANGES-pharmacy items 9, 14; WORKSHOP-BIKEBOOK.md): a pharmacy's NHS charge (RX-CHARGE,
 * 4050) and prepayment certificates (RX-PPC-*, owed on 2270) are not sales and in no VAT box; RX-PRIVATE / RX-SERVICE
 * are sales with no product row. The "Paid in BikeBook" tender (kind PaidElsewhere) is not cash and is its own line.
 */

beforeEach(function () {
    [$this->company, $this->leeds] = TillFixtures::tenant();
    $this->asCompany = fn (Closure $callback) => app(CurrentCompany::class)->runAs($this->company, $callback);
    // A basket of the push sample: its £1.45 zero-rated bread line (N1) becomes the given till line.
    $this->rxBasket = fn (string $number, string $at, int $seq, string $code, string $name) => array_map(function (array $row) use ($number, $code, $name) {
        if ($row['entityId'] === "01K5VB0000000SN1R001{$number}") {
            [$row['payload']['productId'], $row['payload']['name'], $row['payload']['barcode']] = [$code, $name, ''];
        }

        return $row;
    }, ReportFixtures::basket($number, $at, $seq));
});

it('keeps NHS charges and PPCs out of every sales and VAT figure, on their own, still in takings', function () {
    $result = ReportFixtures::push($this->company, $this->leeds, [
        ...($this->rxBasket)('000501', '2026-09-23T10:00:00Z', 500, 'RX-CHARGE', 'NHS prescription charge'),
        ...($this->rxBasket)('000502', '2026-09-23T11:00:00Z', 510, 'RX-PPC-12M', 'Prepayment certificate 12 months'),
        ...($this->rxBasket)('000503', '2026-09-23T12:00:00Z', 520, 'RX-PRIVATE', 'Private prescription'),
    ]);
    expect($result->rejected)->toBe([]);

    expect(ReportFixtures::daily(TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-23'))->toMatchArray([
        'gross' => '12.55', 'net' => '10.69', 'vat' => '1.86', 'txn_count' => '3', 'takings' => '15.45', 'nhs_charges' => '2.90',
        'order_deposits' => '0.00', 'charity' => '0.00',
    ])->and(DB::table(ReportTables::PRODUCT_DAILY)->whereIn('product_id', ['RX-CHARGE', 'RX-PPC-12M'])->count())->toBe(0);

    ($this->asCompany)(function () {
        $scope = ReportScope::tenant('2026-09-23', '2026-09-23', [TillFixtures::LEEDS]);
        $totals = app(SalesReport::class)->totals($scope);

        expect([$totals->gross, $totals->net, $totals->nhsCharges, $totals->takings])->toBe(['12.55', '10.69', '2.90', '15.45'])
            ->and(array_map(fn ($v) => [$v->code, $v->net, $v->vat], app(VatReport::class)->byRate($scope)))->toBe([['S', '9.24', '1.86'], ['Z', '1.45', '0.00']])
            ->and(array_map(fn ($p) => $p->name, app(ProductReport::class)->top($scope, 20)))->toContain('Private prescription')->not->toContain('NHS prescription charge');

        // VAT return helper (boxes 1–9): only the till's sales; the NHS money is in none of them.
        $boxes = collect(VatReturnHelper::for(new AccountsFilters('2026-07-01', '2026-09-30'), new VatQuarter(CarbonImmutable::parse('2026-07-01')))['boxes'])->pluck('amount', 'box');
        expect([$boxes[1], $boxes[6]])->toBe(['1.86', '10']);

        $receipt = SaleReceipt::for(Sale::query()->findOrFail(ReportFixtures::saleId('000501')));
        $line = collect($receipt['lines'])->firstWhere('name', 'NHS prescription charge');
        expect($line['productId'])->toBeNull()->and($line['flags'])->toContain('nhs');
    });
});

it('changes nothing for a shop with no RX- line: no NHS figure, the same sales', function () {
    ReportFixtures::push($this->company, $this->leeds, ReportFixtures::basket('000510', '2026-09-23T10:00:00Z', 600));

    expect(ReportFixtures::daily(TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-23'))->toMatchArray([
        'gross' => '5.15', 'net' => '4.53', 'vat' => '0.62', 'takings' => '5.15', 'nhs_charges' => '0.00',
    ]);
});

it('shows Paid in BikeBook as its own non-cash tender line, never in cash or the drawer', function () {
    $paidElsewhere = '01K9PT000000000000000PT007';
    $basket = array_map(function (array $row) use ($paidElsewhere) {
        if ($row['entity'] === 'SalePayment') {
            [$row['payload']['paymentTypeId'], $row['payload']['paymentTypeName'], $row['payload']['terminalTxnId'], $row['payload']['last4']] = [$paidElsewhere, 'Paid in BikeBook', '', ''];
        }

        return $row;
    }, ReportFixtures::basket('000520', '2026-09-23T10:00:00Z', 700));
    $tender = fn (string $id, string $typeId, float $variance, int $seq) => Rows::envelope('ShiftTender', $id, $seq, [
        'shiftId' => '01K9SH000000000000000SH001', 'paymentTypeId' => $typeId, 'expected' => 50, 'declared' => 50 + $variance, 'terminalTotal' => 0, 'variance' => $variance,
        'branchId' => TillFixtures::LEEDS, 'registerId' => TillFixtures::TILL_1,
    ]);

    $result = ReportFixtures::push($this->company, $this->leeds, [
        Rows::envelope('PaymentType', $paidElsewhere, 690, ['name' => 'Paid in BikeBook', 'position' => 7, 'isActive' => true]),
        Rows::envelope('PaymentType', '01K9PT000000000000000PT001', 691, ['name' => 'Cash', 'position' => 1, 'isCash' => true, 'opensDrawer' => true, 'isActive' => true]),
        ...$basket,
        Rows::envelope('Shift', '01K9SH000000000000000SH001', 710, ['status' => 'closed', 'openedAt' => '2026-09-23T08:00:00Z', 'closedAt' => '2026-09-23T18:00:00Z', 'mode' => 'perTill', 'branchId' => TillFixtures::LEEDS, 'registerId' => TillFixtures::TILL_1]),
        $tender('01K9ST000000000000000ST001', '01K9PT000000000000000PT001', -1.0, 711),
        $tender('01K9ST000000000000000ST002', $paidElsewhere, -51.3, 712),
    ]);
    expect($result->rejected)->toBe([]);

    ($this->asCompany)(function () {
        $scope = ReportScope::tenant('2026-09-23', '2026-09-23', [TillFixtures::LEEDS]);

        expect(array_map(fn ($t) => [$t->name, $t->amount, $t->payments], app(TenderReport::class)->byPaymentType($scope)))->toBe([['Paid in BikeBook', '5.15', 1]])
            ->and(app(OperationsReport::class)->cashVariance($scope))->toBe(['variance' => '-1.00', 'shifts' => 1, 'shortShifts' => 1])
            ->and(app(SalesReport::class)->totals($scope)->takings)->toBe('5.15')
            ->and(SaleReceipt::for(Sale::query()->findOrFail(ReportFixtures::saleId('000520')))['payments'][0]['kind'])->toBe('elsewhere');
    });
});

it('keeps the till\'s out-of-scope SaleVat row (vatRateId "", code E) for NHS charges and PPCs out of the VAT report and every VAT box', function () {
    // EPOS 2026-11-08: RX-CHARGE / RX-PPC-* lines carry vatRateId "" and 0%; the sale's VAT rows then hold one row
    // vatRateId "", percentage 0, vat 0, net = gross = the NHS money, code "E" (out of scope, not exempt).
    $outOfScope = fn (string $number, string $at, int $seq, string $code, string $name) => array_map(function (array $row) use ($number) {
        if ($row['entityId'] === "01K5VB0000000SN1R001{$number}") {
            $row['payload']['vatRateId'] = '';
        }

        if ($row['entityId'] === "01K5VB0000000SVZR001{$number}") {
            [$row['payload']['vatRateId'], $row['payload']['code']] = ['', 'E'];
        }

        return $row;
    }, ($this->rxBasket)($number, $at, $seq, $code, $name));

    $result = ReportFixtures::push($this->company, $this->leeds, [
        ...$outOfScope('000601', '2026-09-24T10:00:00Z', 600, 'RX-CHARGE', 'NHS prescription charge'),
        ...$outOfScope('000602', '2026-09-24T11:00:00Z', 610, 'RX-PPC-3M', 'Prepayment certificate 3 months'),
    ]);
    expect($result->rejected)->toBe([]);

    expect(ReportFixtures::daily(TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-24'))->toMatchArray([
        'gross' => '7.40', 'net' => '6.16', 'vat' => '1.24', 'nhs_charges' => '2.90', 'takings' => '10.30',
    ]);

    ($this->asCompany)(function () {
        $scope = ReportScope::tenant('2026-09-24', '2026-09-24', [TillFixtures::LEEDS]);
        $rates = app(VatReport::class)->byRate($scope);

        expect(array_map(fn ($v) => [$v->code, $v->net, $v->vat], $rates))->toBe([['S', '6.16', '1.24']]);

        $boxes = collect(VatReturnHelper::for(new AccountsFilters('2026-07-01', '2026-09-30'), new VatQuarter(CarbonImmutable::parse('2026-07-01')))['boxes'])->pluck('amount', 'box');
        expect([$boxes[1], $boxes[6]])->toBe(['1.24', '6']);
    });
});
