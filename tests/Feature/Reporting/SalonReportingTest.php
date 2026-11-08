<?php

use App\Domain\Accounts\Data\AccountsFilters;
use App\Domain\Accounts\Data\VatQuarter;
use App\Domain\Accounts\Queries\VatReturnHelper;
use App\Domain\Cash\Queries\ShiftDetail;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\Shift;
use Carbon\CarbonImmutable;
use Tests\Feature\Reporting\ReportFixtures;
use Tests\Feature\Sync\SalonRows;
use Tests\Feature\Sync\Till0185Rows as Rows;
use Tests\Feature\TillData\TillFixtures;

/*
 * Salon pack 2026-11-08 money (W2; README Part C "Sales stay the same shape", UPCOMING-CHANGES "Salon / Barber money"):
 * tips are never in SalePayment.amount, so takings are unchanged; cash tips are drawer movements; a package session
 * or stamp reward is an ordinary line discount; chair rent from renters (ledger 4200) is in VAT box 6.
 */

beforeEach(function () {
    [$this->company, $this->leeds] = TillFixtures::tenant();
    $this->asCompany = fn (Closure $callback) => app(CurrentCompany::class)->runAs($this->company, $callback);
    $this->vat = fn () => ($this->asCompany)(fn () => VatReturnHelper::for(new AccountsFilters('2026-07-01', '2026-09-30'), new VatQuarter(CarbonImmutable::parse('2026-07-01'))));
});

it('keeps takings and sales the same with tips, a renter\'s line and a package session on the sale', function () {
    $basket = array_map(function (array $row) {
        if ($row['entityId'] === '01K5VB0000000SN2R001000601') {
            // A package session: the line's own discount, source promotion (the till's reason "Package: …").
            [$row['payload']['lineDiscount'], $row['payload']['discountSource'], $row['payload']['discountReason']] = [1.0, 'promotion', 'Package: 6 cuts'];
        }

        return $row;
    }, ReportFixtures::basket('000601', '2026-09-23T10:00:00Z', 500));

    $result = ReportFixtures::push($this->company, $this->leeds, [
        ...$basket,
        SalonRows::envelope('SalonTip', SalonRows::id('SalonTip'), 600, ['saleId' => ReportFixtures::saleId('000601'), 'amount' => 2.0, 'method' => 'cash', 'shiftId' => '01K5VB000000000HR001000601']),
        SalonRows::envelope('SalonSaleLineStaff', SalonRows::id('SalonSaleLineStaff'), 601, [
            'saleId' => ReportFixtures::saleId('000601'), 'saleLineId' => '01K5VB0000000SN2R001000601', 'isRenterLine' => true, 'netAmount' => 3.08, 'sharePercent' => 100,
        ]),
        Rows::envelope('CashMovement', '01K9SACM000000000000000601', 602, ['type' => 'tipTaken', 'amount' => 2.0, 'shiftId' => '01K5VB000000000HR001000601', 'reasonId' => '', 'note' => '', 'branchId' => TillFixtures::LEEDS, 'registerId' => TillFixtures::TILL_1]),
    ]);
    expect($result->rejected)->toBe([]);

    // The plain basket (Till0185ReportingTest): gross 5.15, net 4.53, takings 5.15. The tip is not a sale or takings;
    // the renter's line stays a sale (EPOS question Q-S1); the package session is a discount, not a staff one.
    expect(ReportFixtures::daily(TillFixtures::LEEDS, TillFixtures::TILL_1, '2026-09-23'))->toMatchArray([
        'gross' => '5.15', 'net' => '4.53', 'vat' => '0.62', 'takings' => '5.15', 'txn_count' => '1',
        'discount' => '1.00', 'promo' => '0.00', 'staff_discount' => '0.00',
    ]);
});

it('shows cash tips in the drawer: tips taken in, a refunded tip back out, tips paid out negative', function () {
    $shift = '01K9SASH000000000000000001';
    $movement = fn (string $id, string $type, float $amount, int $seq) => Rows::envelope('CashMovement', $id, $seq, [
        'type' => $type, 'amount' => $amount, 'shiftId' => $shift, 'reasonId' => '', 'note' => '', 'at' => '2026-09-23T1'.$seq.':00:00Z',
        'branchId' => TillFixtures::LEEDS, 'registerId' => TillFixtures::TILL_1,
    ]);

    $result = ReportFixtures::push($this->company, $this->leeds, [
        Rows::envelope('Shift', $shift, 1, ['status' => 'closed', 'openedAt' => '2026-09-23T08:00:00Z', 'closedAt' => '2026-09-23T18:00:00Z', 'mode' => 'perTill', 'branchId' => TillFixtures::LEEDS, 'registerId' => TillFixtures::TILL_1]),
        $movement('01K9SACM000000000000000001', 'paidIn', 5.0, 2),
        $movement('01K9SACM000000000000000002', 'tipTaken', 3.5, 3),
        $movement('01K9SACM000000000000000003', 'tipTaken', -1.0, 4),      // a cash tip refunded with its sale
        $movement('01K9SACM000000000000000004', 'tipPayout', -20.0, 5),
    ]);
    expect($result->rejected)->toBe([]);

    $detail = ($this->asCompany)(fn () => ShiftDetail::for(Shift::query()->findOrFail($shift)));

    expect($detail['movementTotals'])->toBe([
        ['type' => 'paidIn', 'count' => 1, 'total' => '5.00'],
        ['type' => 'tipTaken', 'count' => 2, 'total' => '2.50'],
        ['type' => 'tipPayout', 'count' => 1, 'total' => '-20.00'],
    ])
        ->and(array_column($detail['movements'], 'type'))->toBe(['paidIn', 'tipTaken', 'tipTaken', 'tipPayout'])
        ->and(array_column($detail['movements'], 'amount'))->toBe(['5.00', '3.50', '-1.00', '-20.00']);

    // The drawer view's words (resources/js/components/app/cash/format.tsx MOVEMENT_TYPES).
    $words = (string) file_get_contents(resource_path('js/components/app/cash/format.tsx'));
    expect($words)->toContain("tipTaken: 'Tips taken'")->toContain("tipPayout: 'Tips paid out'");
});

it('puts chair rent from renters (4200) in VAT box 6 and its VAT in box 1; packages and tips in neither', function () {
    $account = fn (string $code, string $name, string $type, ?int $box, int $seq) => Rows::envelope('Account', '01K9SAAC00000000000000'.$code, $seq, [
        'code' => $code, 'name' => $name, 'type' => $type, 'parentCode' => null, 'vatBox' => $box, 'isSystem' => true, 'isActive' => true, 'isDebitBalance' => false,
    ]);
    $entry = fn (string $id, string $type, array $lines, int $seq) => [
        Rows::envelope('JournalEntry', $id, $seq, ['refType' => $type, 'refId' => 'x', 'memo' => $type, 'date' => '2026-09-23', 'totalDebits' => 1, 'totalCredits' => 1]),
        ...array_map(fn (array $l, int $i) => Rows::envelope('JournalLine', substr($id, 0, 24).sprintf('%02d', $i), $seq + 1 + $i, [
            'journalEntryId' => $id, 'accountId' => '01K9SAAC00000000000000'.$l[0], 'accountCode' => $l[0], 'debit' => $l[1], 'credit' => $l[2], 'vatRateId' => null,
        ]), $lines, array_keys($lines)),
    ];

    $result = ReportFixtures::push($this->company, $this->leeds, [
        ...ReportFixtures::basket('000610', '2026-09-23T10:00:00Z', 1),
        $account('2200', 'VAT on sales', 'liability', 1, 20),
        $account('4200', 'Chair rent from renters', 'income', 6, 21),
        $account('2290', 'Chair renter takings held', 'liability', null, 22),
        $account('2300', 'Packages sold not used', 'liability', null, 23),
        // A renter's statement: £100 rent + £20 VAT taken from the takings the salon held for them.
        ...$entry('01K9SAJE00000000000RS00100', 'SalonRenterStatement', [['2290', 120, 0], ['4200', 0, 100], ['2200', 0, 20]], 30),
        // Packages and tips move between 2300 / 2280 and sales or cash: not chair rent.
        ...$entry('01K9SAJE00000000000PK00100', 'SalonPackageSold', [['4000', 50, 0], ['2300', 0, 50]], 40),
        ...$entry('01K9SAJE00000000000TP00100', 'SalonTip', [['1210', 5, 0], ['2280', 0, 5]], 50),
    ]);
    expect($result->rejected)->toBe([]);

    $vat = ($this->vat)();
    $boxes = collect($vat['boxes'])->pluck('amount', 'box');

    expect([$boxes[1], $boxes[6]])->toBe(['20.62', '104'])
        ->and(collect($vat['sources'])->firstWhere('key', 'chairRent'))->toBe(['key' => 'chairRent', 'label' => 'Chair rent from renters (account 4200)', 'net' => '100.00', 'vat' => '20.00', 'count' => 1, 'boxes' => '1, 6']);
});

it('shows a business with no chair rent exactly the VAT return it saw before', function () {
    ReportFixtures::push($this->company, $this->leeds, ReportFixtures::basket('000620', '2026-09-23T10:00:00Z', 1));

    $vat = ($this->vat)();

    expect(collect($vat['boxes'])->pluck('amount', 'box')->only([1, 6])->all())->toBe([1 => '0.62', 6 => '4'])
        ->and(array_column($vat['sources'], 'key'))->toBe(['sales', 'invoices', 'credits', 'expenses']);
});
