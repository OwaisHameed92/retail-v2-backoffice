<?php

use App\Domain\Tenancy\Enums\CompanyRole;
use Carbon\CarbonImmutable;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\TillData\TillFixtures;

/*
 * Module 5.4: banking, safe counts, card settlement reconciliation, day locks and variance alerts. "Now" is Wed
 * 23 Sept 2026 18:00 London; the default range is 17–23 Sept.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-23 18:00', 'Europe/London'));
    [$this->company] = TillFixtures::tenant();
    $this->owner = C::member($this->company, CompanyRole::Owner);
    $this->id = $this->company->id;
    C::row('till_users', ['id' => 'USER0000000000000000000001', 'company_id' => $this->id, 'name' => 'Asha']);
});

test('banking: the rows as the shop recorded them, and totals leave cancelled ones out', function () {
    $bank = fn (string $n, array $row) => C::row('cash_office_bankings', ['id' => '01K5T0Q8C40000000000BANK'.$n, 'company_id' => $this->id, 'branch_id' => TillFixtures::LEEDS, 'prepared_at' => '2026-09-22 17:00:00', ...$row]);
    $bank('01', ['reference' => 'BNK-1', 'amount' => '500.00', 'status' => 'banked', 'collection_method' => 'carrier', 'carrier_name' => 'Loomis', 'prepared_by_user_id' => 'USER0000000000000000000001', 'collected_at' => '2026-09-23 09:00:00', 'banked_at' => '2026-09-23 12:00:00', 'confirmed_amount' => '495.00', 'variance_amount' => '-5.00', 'seal_number' => 'S123']);
    $bank('02', ['reference' => 'BNK-2', 'amount' => '300.00', 'status' => 'prepared', 'collection_method' => 'ownBanking']);
    $bank('03', ['reference' => 'BNK-3', 'amount' => '999.00', 'status' => 'cancelled']);
    $bank('04', ['reference' => 'OLD', 'amount' => '50.00', 'status' => 'banked', 'prepared_at' => '2026-09-01 10:00:00']);

    $props = C::props($this->actingAs($this->owner)->get('/app/cash/banking?shop=all'));

    expect(array_column($props['bankings']['data'], 'reference'))->toEqualCanonicalizing(['BNK-1', 'BNK-2', 'BNK-3'])
        ->and(collect($props['bankings']['data'])->firstWhere('reference', 'BNK-1'))->toMatchArray([
            'amount' => '500.00', 'status' => 'banked', 'method' => 'carrier', 'carrier' => 'Loomis', 'preparedBy' => 'Asha', 'confirmedAmount' => '495.00',
            'variance' => '-5.00', 'sealNumber' => 'S123', 'bankedAt' => '2026-09-23T12:00:00Z', 'shop' => 'Leeds',
        ])
        ->and($props['summary'])->toBe(['count' => 2, 'total' => '800.00', 'waiting' => 1, 'variance' => '-5.00']);
});

test('safe counts: expected, counted and variance as stored, with short and over totals', function () {
    $count = fn (string $n, string $day, string $expected, string $counted, string $variance) => C::row('cash_office_reconciliations', [
        'id' => '01K5T0Q8C40000000000SAFE'.$n, 'company_id' => $this->id, 'branch_id' => TillFixtures::BRADFORD, 'trading_date' => $day,
        'expected_balance' => $expected, 'counted_balance' => $counted, 'variance' => $variance, 'counted_at' => $day.' 20:00:00',
        'counted_by_user_id' => 'USER0000000000000000000001', 'denominations_json' => json_encode(['20.00' => 10, '10.00' => 3]),
    ]);
    $count('01', '2026-09-20', '1000.00', '990.00', '-10.00');
    $count('02', '2026-09-22', '800.00', '802.50', '2.50');
    $count('03', '2026-09-01', '1.00', '0.00', '-1.00');

    $props = C::props($this->actingAs($this->owner)->get('/app/cash/counts?shop=all'));

    expect(array_column($props['counts']['data'], 'day'))->toBe(['2026-09-22', '2026-09-20'])
        ->and($props['counts']['data'][1])->toMatchArray(['expected' => '1000.00', 'counted' => '990.00', 'variance' => '-10.00', 'countedBy' => 'Asha', 'shop' => 'Bradford'])
        ->and($props['counts']['data'][1]['denominations'])->toBe([['denomination' => '20.00', 'count' => 10], ['denomination' => '10.00', 'count' => 3]])
        ->and($props['summary'])->toBe(['count' => 2, 'variance' => '-7.50', 'short' => 1, 'over' => 1]);
});

test('card reconciliation compares each till\'s card takings with its settlements per day and flags differences', function () {
    // Till 1, 22 Sept: 250.00 card takings over two shifts, settled 250.00 → matched.
    C::shift($this->id, TillFixtures::LEEDS, TillFixtures::TILL_1, '01K5T0Q8C40000000000SHFT11', ['status' => 'closed', 'closed_at' => '2026-09-22 12:00:00'], null, ['100.00', '100.00', '100.00']);
    C::shift($this->id, TillFixtures::LEEDS, TillFixtures::TILL_1, '01K5T0Q8C40000000000SHFT12', ['status' => 'closed', 'closed_at' => '2026-09-22 20:00:00'], null, ['150.00', '150.00', '150.00']);
    // Till 2, 22 Sept: 80.00 taken, 75.50 settled → −4.50 difference. Till 2, 21 Sept: 40.00 taken, no settlement.
    C::shift($this->id, TillFixtures::LEEDS, TillFixtures::TILL_2, '01K5T0Q8C40000000000SHFT13', ['status' => 'closed', 'closed_at' => '2026-09-22 18:00:00'], null, ['80.00', '80.00', '80.00']);
    C::shift($this->id, TillFixtures::LEEDS, TillFixtures::TILL_2, '01K5T0Q8C40000000000SHFT14', ['status' => 'closed', 'closed_at' => '2026-09-21 18:00:00'], null, ['40.00', '40.00', '40.00']);
    // Bradford, 20 Sept: a failed settlement and no shift.
    $settle = fn (string $n, string $register, string $branch, string $day, string $terminal, string $status) => C::row('card_settlements', [
        'id' => '01K5T0Q8C40000000000CARD'.$n, 'company_id' => $this->id, 'branch_id' => $branch, 'register_id' => $register, 'trading_date' => $day,
        'provider' => 'Dojo', 'batch_reference' => 'B'.$n, 'terminal_total' => $terminal, 'pos_total' => $terminal, 'variance' => '0.00', 'fees' => '0.00',
        'transaction_count' => 3, 'status' => $status, 'settled_at' => $day.' 21:00:00',
    ]);
    $settle('01', TillFixtures::TILL_1, TillFixtures::LEEDS, '2026-09-22', '250.00', 'matched');
    $settle('02', TillFixtures::TILL_2, TillFixtures::LEEDS, '2026-09-22', '75.50', 'mismatched');
    $settle('03', TillFixtures::BRADFORD_TILL, TillFixtures::BRADFORD, '2026-09-20', '60.00', 'failed');

    $props = C::props($this->actingAs($this->owner)->get('/app/cash/cards?shop=all'));
    $rows = collect($props['days']['data'])->keyBy(fn ($r) => $r['registerId'].'|'.$r['day']);

    expect($rows->get(TillFixtures::TILL_1.'|2026-09-22'))->toMatchArray(['tillCard' => '250.00', 'settled' => '250.00', 'difference' => '0.00', 'flag' => 'matched', 'shifts' => 2])
        ->and($rows->get(TillFixtures::TILL_2.'|2026-09-22'))->toMatchArray(['tillCard' => '80.00', 'settled' => '75.50', 'difference' => '-4.50', 'flag' => 'difference', 'till' => 'Till 2'])
        ->and($rows->get(TillFixtures::TILL_2.'|2026-09-21'))->toMatchArray(['tillCard' => '40.00', 'settled' => null, 'difference' => null, 'flag' => 'notSettled'])
        ->and($rows->get(TillFixtures::BRADFORD_TILL.'|2026-09-20'))->toMatchArray(['tillCard' => null, 'settled' => null, 'flag' => 'failed'])
        ->and(array_column($props['days']['data'], 'day'))->toBe(['2026-09-22', '2026-09-22', '2026-09-21', '2026-09-20'])
        ->and($props['summary'])->toBe(['days' => 4, 'flagged' => 3, 'tillCard' => '370.00', 'settled' => '325.50', 'difference' => '-4.50']);
});

test('day locks per shop and day: locked, reopened, not locked yet and today, read only', function () {
    $lock = fn (string $n, string $branch, string $day, bool $locked, array $more = []) => C::row('day_locks', [
        'id' => '01K5T0Q8C40000000000LOCK'.$n, 'company_id' => $this->id, 'branch_id' => $branch, 'trading_date' => $day,
        'locked_at' => $day.' 22:00:00', 'locked_by' => 'USER0000000000000000000001', 'is_locked' => $locked, ...$more,
    ]);
    $lock('01', TillFixtures::LEEDS, '2026-09-22', true);
    $lock('02', TillFixtures::BRADFORD, '2026-09-22', false, ['unlocked_at' => '2026-09-23 08:00:00', 'unlocked_by' => 'USER0000000000000000000001', 'unlock_reason' => 'Late refund']);

    $props = C::props($this->actingAs($this->owner)->get('/app/cash/days?shop=all&from=2026-09-21&to=2026-09-30'));
    $rows = collect($props['days']['data'])->keyBy(fn ($r) => $r['shop'].'|'.$r['day']);

    expect($props['days']['meta']['total'])->toBe(6) // 21–23 Sept (not the future) × 2 shops
        ->and($rows->get('Leeds|2026-09-22'))->toMatchArray(['status' => 'locked', 'lockedBy' => 'Asha', 'lockedAt' => '2026-09-22T22:00:00Z', 'unlockedAt' => null])
        ->and($rows->get('Bradford|2026-09-22'))->toMatchArray(['status' => 'unlocked', 'unlockedBy' => 'Asha', 'reason' => 'Late refund'])
        ->and($rows->get('Leeds|2026-09-21')['status'])->toBe('open')
        ->and($rows->get('Leeds|2026-09-23')['status'])->toBe('today')
        ->and($props['summary'])->toBe(['locked' => 1, 'unlocked' => 1, 'open' => 2]);

    // There is nothing to lock or unlock from the portal.
    $this->actingAs($this->owner)->post('/app/cash/days', [])->assertStatus(405);
});

test('variance alerts use each shop\'s alert setting, the till\'s own flag, and a threshold typed in', function () {
    C::row('till_settings', ['id' => '01K5T0Q8C40000000000SETT01', 'company_id' => $this->id, 'scope' => 'company', 'scope_id' => '', 'setting_key' => 'cash.variance_alert_over', 'value' => '10']);
    C::row('till_settings', ['id' => '01K5T0Q8C40000000000SETT02', 'company_id' => $this->id, 'scope' => 'branch', 'scope_id' => TillFixtures::BRADFORD, 'setting_key' => 'cash.variance_alert_over', 'value' => '2.00']);
    $closed = fn (string $at) => ['status' => 'closed', 'closed_at' => $at];
    C::shift($this->id, TillFixtures::LEEDS, TillFixtures::TILL_1, '01K5T0Q8C40000000000SHFT21', $closed('2026-09-22 16:00:00'), ['100.00', '88.00', '-12.00']);   // Leeds ≥ 10: alert
    C::shift($this->id, TillFixtures::LEEDS, TillFixtures::TILL_1, '01K5T0Q8C40000000000SHFT22', $closed('2026-09-21 16:00:00'), ['100.00', '97.00', '-3.00']);    // Leeds < 10
    C::shift($this->id, TillFixtures::BRADFORD, TillFixtures::BRADFORD_TILL, '01K5T0Q8C40000000000SHFT23', $closed('2026-09-21 17:00:00'), ['50.00', '53.00', '3.00']); // Bradford ≥ 2
    C::shift($this->id, TillFixtures::LEEDS, TillFixtures::TILL_2, '01K5T0Q8C40000000000SHFT24', $closed('2026-09-20 16:00:00'), ['100.00', '99.00', '-1.00']);  // flagged by the till
    C::row('z_reports', ['id' => '01K5T0Q8C40000000000ZREP24', 'company_id' => $this->id, 'branch_id' => TillFixtures::LEEDS, 'register_id' => TillFixtures::TILL_2, 'shift_id' => '01K5T0Q8C40000000000SHFT24',
        'sequence_no' => 7, 'period_end' => '2026-09-20 16:00:00', 'totals_json' => '{"VarianceTotal":-1.0,"HasVarianceWarning":true}']);
    C::row('cash_office_reconciliations', ['id' => '01K5T0Q8C40000000000SAFE11', 'company_id' => $this->id, 'branch_id' => TillFixtures::LEEDS, 'trading_date' => '2026-09-22', 'variance' => '-15.00', 'counted_at' => '2026-09-22 21:00:00']);

    $props = C::props($this->actingAs($this->owner)->get('/app/cash/alerts?shop=all'));
    expect(array_column($props['alerts']['data'], 'id'))->toBe(['01K5T0Q8C40000000000SAFE11', '01K5T0Q8C40000000000SHFT21', '01K5T0Q8C40000000000SHFT23', '01K5T0Q8C40000000000SHFT24'])
        ->and($props['alerts']['data'][1])->toMatchArray(['kind' => 'shift', 'what' => 'Cash', 'variance' => '-12.00', 'threshold' => '10.00', 'tillFlag' => false, 'shop' => 'Leeds'])
        ->and($props['alerts']['data'][2])->toMatchArray(['variance' => '3.00', 'threshold' => '2.00', 'shop' => 'Bradford'])
        ->and($props['alerts']['data'][3])->toMatchArray(['tillFlag' => true, 'variance' => '-1.00'])
        ->and($props['summary'])->toMatchArray(['total' => 4, 'short' => 3, 'shifts' => 3, 'office' => 1, 'defaultThreshold' => '10.00', 'threshold' => null]);

    $typed = C::props($this->actingAs($this->owner)->get('/app/cash/alerts?shop=all&threshold=2.5'));
    expect($typed['summary'])->toMatchArray(['total' => 5, 'threshold' => '2.50']);
});
