<?php

use App\Domain\Tenancy\Enums\CompanyRole;
use Carbon\CarbonImmutable;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\TillData\TillFixtures;

/*
 * Module 5.4: shifts, one shift and Z reports, every figure equal to the stored till rows. "Now" is Wed 23 Sept 2026
 * 18:00 London (17:00 UTC); the default range is 17–23 Sept.
 */

const SHIFT_A = '01K5T0Q8C40000000000SHFT01';
const SHIFT_B = '01K5T0Q8C40000000000SHFT02';
const SHIFT_OPEN = '01K5T0Q8C40000000000SHFT03';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-23 18:00', 'Europe/London'));
    [$this->company] = TillFixtures::tenant();
    $this->owner = C::member($this->company, CompanyRole::Owner);
    $id = $this->company->id;

    C::row('till_users', ['id' => 'USER0000000000000000000001', 'company_id' => $id, 'name' => 'Asha']);
    C::shift($id, TillFixtures::LEEDS, TillFixtures::TILL_1, SHIFT_A,
        ['opened_at' => '2026-09-22 06:00:00', 'closed_at' => '2026-09-22 16:00:00', 'status' => 'closed', 'variance_total' => '-2.25', 'opening_float' => '150.00', 'closed_by' => 'USER0000000000000000000001'],
        ['412.30', '409.80', '-2.50'], ['250.00', '250.25', '250.25']);
    C::shift($id, TillFixtures::BRADFORD, TillFixtures::BRADFORD_TILL, SHIFT_B,
        ['opened_at' => '2026-09-21 07:00:00', 'closed_at' => '2026-09-21 15:00:00', 'status' => 'closed', 'variance_total' => '1.00'],
        ['80.00', '81.00', '1.00']);
    C::shift($id, TillFixtures::LEEDS, TillFixtures::TILL_2, SHIFT_OPEN, ['opened_at' => '2026-09-23 07:00:00']);
    C::shift($id, TillFixtures::LEEDS, TillFixtures::TILL_1, '01K5T0Q8C40000000000SHFT09', ['opened_at' => '2026-09-01 07:00:00', 'status' => 'closed', 'variance_total' => '-40.00']);
    C::row('z_reports', [
        'id' => '01K5T0Q8C40000000000ZREP01', 'company_id' => $id, 'branch_id' => TillFixtures::LEEDS, 'register_id' => TillFixtures::TILL_1, 'shift_id' => SHIFT_A,
        'sequence_no' => 41, 'period_start' => '2026-09-22 06:00:00', 'period_end' => '2026-09-22 16:00:00', 'generated_at' => '2026-09-22 16:00:05', 'reprint_count' => 1,
        'totals_json' => json_encode(['Tenders' => [['PaymentTypeId' => C::type($id, 'CASH'), 'PaymentTypeName' => 'Cash', 'Expected' => 412.3, 'Declared' => 409.8, 'TerminalTotal' => 0, 'Variance' => -2.5, 'ExceedsThreshold' => true]], 'VarianceTotal' => -2.25, 'HasVarianceWarning' => true, 'VarianceAlertOver' => 2.0]),
    ]);
});

test('the shifts list shows the till\'s figures for shifts opened in the range, newest first', function () {
    $props = C::props($this->actingAs($this->owner)->get('/app/cash?shop=all'));

    expect(array_column($props['shifts']['data'], 'id'))->toBe([SHIFT_OPEN, SHIFT_A, SHIFT_B])
        ->and($props['shifts']['data'][1])->toMatchArray([
            'status' => 'closed', 'shop' => 'Leeds', 'till' => 'Till 1', 'user' => 'Asha', 'closedBy' => 'Asha', 'float' => '150.00',
            'cashExpected' => '412.30', 'cashCounted' => '409.80', 'cashVariance' => '-2.50', 'variance' => '-2.25', 'z' => 41, 'warning' => true,
            'openedAt' => '2026-09-22T06:00:00Z', 'closedAt' => '2026-09-22T16:00:00Z',
        ])
        ->and($props['shifts']['data'][0])->toMatchArray(['status' => 'open', 'variance' => null, 'cashExpected' => null])
        ->and($props['summary'])->toMatchArray(['shifts' => 3, 'open' => 1, 'cashVariance' => '-1.50', 'short' => 1, 'over' => 1, 'waitingCount' => 0]);

    $open = C::props($this->actingAs($this->owner)->get('/app/cash?shop=all&status=open&from=2026-09-01&to=2026-09-02'));
    expect(array_column($open['shifts']['data'], 'id'))->toBe([SHIFT_OPEN]);

    $till = C::props($this->actingAs($this->owner)->get('/app/cash?shop=all&till='.TillFixtures::BRADFORD_TILL));
    expect(array_column($till['shifts']['data'], 'id'))->toBe([SHIFT_B]);
});

test('no-shift cash waiting for a shift is counted, and adopted cash shows on the shift as before it opened', function () {
    $id = $this->company->id;
    C::row('cash_movements', ['id' => '01K5T0Q8C40000000000MOVE01', 'company_id' => $id, 'branch_id' => TillFixtures::LEEDS, 'register_id' => TillFixtures::TILL_2, 'shift_id' => '', 'type' => 'accountPayment', 'amount' => '12.00', 'at' => '2026-09-23 16:30:00']);
    C::row('cash_movements', ['id' => '01K5T0Q8C40000000000MOVE02', 'company_id' => $id, 'branch_id' => TillFixtures::LEEDS, 'register_id' => TillFixtures::TILL_1, 'shift_id' => SHIFT_A, 'type' => 'accountPayment', 'amount' => '20.00', 'at' => '2026-09-22 05:40:00']);
    C::row('cash_movements', ['id' => '01K5T0Q8C40000000000MOVE03', 'company_id' => $id, 'branch_id' => TillFixtures::LEEDS, 'register_id' => TillFixtures::TILL_1, 'shift_id' => SHIFT_A, 'type' => 'paidOut', 'amount' => '7.50', 'note' => 'Milk', 'at' => '2026-09-22 09:00:00']);
    C::row('cash_movements', ['id' => '01K5T0Q8C40000000000MOVE04', 'company_id' => $id, 'branch_id' => TillFixtures::LEEDS, 'register_id' => TillFixtures::TILL_1, 'shift_id' => SHIFT_A, 'type' => 'paidIn', 'amount' => '5.00', 'at' => '2026-09-22 10:00:00']);

    $list = C::props($this->actingAs($this->owner)->get('/app/cash?shop=all'));
    expect($list['summary'])->toMatchArray(['waitingCount' => 1, 'waitingTotal' => '12.00']);

    $shift = C::props($this->actingAs($this->owner)->get('/app/cash/shifts/'.SHIFT_A));
    expect(array_column($shift['movements'], 'type'))->toBe(['accountPayment', 'paidOut', 'paidIn'])
        ->and(array_column($shift['movements'], 'adopted'))->toBe([true, false, false])
        ->and($shift['movements'][1]['note'])->toBe('Milk')
        ->and($shift['movementTotals'])->toBe([
            ['type' => 'paidIn', 'count' => 1, 'total' => '5.00'],
            ['type' => 'paidOut', 'count' => 1, 'total' => '7.50'],
            ['type' => 'accountPayment', 'count' => 1, 'total' => '20.00'],
        ]);
});

test('one shift: tenders as stored, expected against counted per stage with the variance, the Z totals and its sales', function () {
    $id = $this->company->id;
    $count = fn (string $suffix, string $stage, string $at, string $denomination, int $n) => C::row('cash_counts', [
        'id' => '01K5T0Q8C4000000000CNT'.$suffix, 'company_id' => $id, 'branch_id' => TillFixtures::LEEDS, 'register_id' => TillFixtures::TILL_1, 'shift_id' => SHIFT_A,
        'stage' => $stage, 'denomination' => $denomination, 'count' => $n, 'total' => bcmul($denomination, (string) $n, 2), 'at' => $at, 'user_id' => 'USER0000000000000000000001',
    ]);
    $count('0001', 'open', '2026-09-22 06:00:00', '20.00', 5);
    $count('0002', 'open', '2026-09-22 06:00:00', '10.00', 5);    // 150.00: the float
    $count('0003', 'spot', '2026-09-22 11:00:00', '20.00', 12);    // 240.00
    $count('0004', 'close', '2026-09-22 16:00:00', '20.00', 20);
    $count('0005', 'close', '2026-09-22 16:00:00', '0.10', 3);     // 400.30 against 412.30: −12.00
    C::row('sales', ['id' => '01K5T0Q8C40000000000SALE01', 'company_id' => $id, 'branch_id' => TillFixtures::LEEDS, 'register_id' => TillFixtures::TILL_1, 'shift_id' => SHIFT_A, 'type' => 'sale', 'status' => 'completed', 'total' => '12.40']);
    C::row('sales', ['id' => '01K5T0Q8C40000000000SALE02', 'company_id' => $id, 'branch_id' => TillFixtures::LEEDS, 'register_id' => TillFixtures::TILL_1, 'shift_id' => SHIFT_A, 'type' => 'sale', 'status' => 'completed', 'total' => '7.60']);
    C::row('sales', ['id' => '01K5T0Q8C40000000000SALE03', 'company_id' => $id, 'branch_id' => TillFixtures::LEEDS, 'register_id' => TillFixtures::TILL_1, 'shift_id' => SHIFT_A, 'type' => 'sale', 'status' => 'voided', 'total' => '99.00']);

    $props = C::props($this->actingAs($this->owner)->get('/app/cash/shifts/'.SHIFT_A));

    expect($props['shift'])->toMatchArray(['float' => '150.00', 'variance' => '-2.25', 'openedBy' => 'Asha', 'closedBy' => 'Asha', 'salesCount' => 2, 'salesTotal' => '20.00', 'shop' => 'Leeds', 'till' => 'Till 1'])
        ->and($props['tenders'])->toHaveCount(2)
        ->and($props['tenders'][0])->toMatchArray(['name' => 'Cash', 'cash' => true, 'expected' => '412.30', 'declared' => '409.80', 'variance' => '-2.50'])
        ->and($props['tenders'][1])->toMatchArray(['name' => 'Card', 'expected' => '250.00', 'declared' => '250.25', 'terminal' => '250.25', 'variance' => '0.25'])
        ->and(array_map(fn ($s) => [$s['stage'], $s['expected'], $s['counted'], $s['variance']], $props['stages']))->toBe([
            ['open', '150.00', '150.00', '0.00'],
            ['spot', null, '240.00', null],
            ['close', '412.30', '400.30', '-12.00'],
        ])
        ->and($props['stages'][2]['lines'])->toHaveCount(2)
        ->and($props['z'])->toMatchArray(['sequenceNo' => 41])
        ->and($props['z']['totals'])->toMatchArray(['variance' => '-2.25', 'warning' => true, 'alertOver' => '2.00', 'readable' => true])
        ->and($props['z']['totals']['tenders'][0])->toMatchArray(['name' => 'Cash', 'expected' => '412.30', 'declared' => '409.80', 'variance' => '-2.50', 'exceedsThreshold' => true]);
});

test('Z reports per till and day, and one Z report with the till\'s own figures', function () {
    $list = C::props($this->actingAs($this->owner)->get('/app/cash/z?shop=all'));
    expect($list['reports']['data'])->toHaveCount(1)
        ->and($list['reports']['data'][0])->toMatchArray(['sequenceNo' => 41, 'day' => '2026-09-22', 'shop' => 'Leeds', 'till' => 'Till 1', 'reprints' => 1, 'variance' => '-2.25', 'warning' => true, 'shiftId' => SHIFT_A]);

    expect(C::props($this->actingAs($this->owner)->get('/app/cash/z?shop=all&from=2026-09-23&to=2026-09-23'))['reports']['data'])->toBe([]);

    $one = C::props($this->actingAs($this->owner)->get('/app/cash/z/01K5T0Q8C40000000000ZREP01'));
    expect($one['report'])->toMatchArray(['sequenceNo' => 41, 'generatedAt' => '2026-09-22T16:00:05Z', 'shiftId' => SHIFT_A])
        ->and($one['report']['totals']['tenders'])->toHaveCount(1);
});
