<?php

namespace App\Domain\Demo\Builders;

use App\Domain\Demo\Support\DemoBusiness;
use App\Domain\Demo\Support\DemoJournal;
use App\Domain\Demo\Support\DemoPush;
use App\Domain\Demo\Support\SaleTakings;
use App\Domain\Reporting\Demo\DemoShop;
use App\Domain\Reporting\Support\TradingDay;
use Carbon\CarbonImmutable;

/**
 * The cash-up of every demo shift (each cashier's drawer, as the sales name it): opening float, paid-outs and safe
 * drops, the counted cash by denomination with small variances (now and then a bigger one), the card total against
 * the terminal; a Z report per till and day; and the day's takings journal. Today's latest shift per till is still
 * open. Returns per shop and day what went to the safe and what the card terminals took, for CashOfficeBuilder.
 *
 * @phpstan-type TillDay array{shop: DemoShop, register: string, day: string, card: int, cardCount: int, banked: int, closed: bool}
 */
final class CashBuilder
{
    public const FLOAT = 10000;

    /** Denominations counted at cash-up, pence. */
    public const DENOMINATIONS = [2000, 1000, 500, 200, 100, 50, 20, 10, 5, 2, 1];

    /**
     * @return list<TillDay>
     */
    public function handle(DemoBusiness $b, DemoPush $push, DemoJournal $journal): array
    {
        $shops = [];
        $ids = [];

        foreach ($b->shops as ['shop' => $shop]) {
            $shops[$shop->branchId] = $shop;

            foreach ($shop->registers as $register) {
                foreach ($shop->cashiers as $user) {
                    foreach (TradingDay::range($b->from, $b->today) as $day) {
                        $ids[$shop->id("shift|{$register['id']}|{$user}|{$day}")] = true;
                    }
                }
            }
        }

        $shifts = SaleTakings::byShift($b, $ids);
        uasort($shifts, fn (array $x, array $y) => [$x['register'], $x['first']] <=> [$y['register'], $y['first']]);
        $latest = [];

        foreach ($shifts as $id => $s) {
            $latest[$s['register']] = $s['day'] === $b->today ? $id : ($latest[$s['register']] ?? null);
        }

        $days = [];

        foreach ($shifts as $id => $s) {
            $shop = $shops[$s['branch']];
            $open = $latest[$s['register']] === $id && $b->now->setTimezone(TradingDay::timezone())->hour < 22;
            $result = $this->shift($b, $push, $shop, (string) $id, $s, $open);
            $key = "{$s['register']}|{$s['day']}";
            $days[$key] ??= ['shop' => $shop, 'register' => $s['register'], 'day' => $s['day'], 'card' => 0, 'cardCount' => 0, 'banked' => 0, 'closed' => true, 'shifts' => [], 'variance' => 0, 'paidOut' => 0];
            $days[$key]['card'] += $s['card'];
            $days[$key]['cardCount'] += $result['cardCount'];
            $days[$key]['banked'] += $result['banked'];
            $days[$key]['variance'] += $result['variance'];
            $days[$key]['paidOut'] += $result['paidOut'];
            $days[$key]['closed'] = $days[$key]['closed'] && ! $open;
            $days[$key]['shifts'][] = [$id, $result];
        }

        $takings = SaleTakings::byTillDay($b);
        $out = [];

        foreach ($days as $key => $day) {
            if ($day['closed']) {
                $this->zReport($b, $push, $day);
                $this->journals($journal, $day, $takings[$key] ?? ['bands' => [], 'cost' => 0]);
            }

            unset($day['shifts'], $day['variance'], $day['paidOut']);
            $out[] = $day;
        }

        return $out;
    }

    /**
     * @param  array{branch: string, register: string, user: string, day: string, first: string, last: string, cash: int, cashback: int, card: int, count: int}  $s
     * @return array{banked: int, variance: int, paidOut: int, cardCount: int, expected: int, declared: int, opened: CarbonImmutable, closed: CarbonImmutable|null, user: string, card: int, terminal: int}
     */
    private function shift(DemoBusiness $b, DemoPush $push, DemoShop $shop, string $id, array $s, bool $open): array
    {
        $rng = $b->rng("cash|{$id}");
        $opened = CarbonImmutable::parse($s['first'], 'UTC')->subMinutes($rng->getInt(6, 25));
        $closed = $open ? null : CarbonImmutable::parse($s['last'], 'UTC')->addMinutes($rng->getInt(4, 15));
        $paidOut = $rng->nextFloat() < 0.1 ? $rng->getInt(5, 20) * 100 : 0;
        $drawer = self::FLOAT + $s['cash'] - $s['cashback'] - $paidOut;
        $drop = $drawer > 30000 ? intdiv($drawer - 10000, 5000) * 5000 : 0;
        $expected = $drawer - $drop;
        $roll = $rng->nextFloat();
        $variance = match (true) {
            $open || $roll < 0.66 => 0, $roll < 0.9 => $rng->getInt(-200, 150), $roll < 0.98 => $rng->getInt(-1000, 500), default => -$rng->getInt(1000, 2500)
        };
        $declared = $expected + $variance;
        $terminal = $s['card'] - ($rng->nextFloat() < 0.03 ? $rng->getInt(1, 20) * 100 : 0);
        $at = $closed ?? $b->now;
        $base = ['registerId' => $s['register'], 'branchId' => $shop->branchId];

        $push->add($shop, 'Shift', $id, [
            'userId' => $s['user'], 'openedAt' => DemoBusiness::iso($opened), 'closedAt' => $closed === null ? null : DemoBusiness::iso($closed),
            'openingFloat' => self::FLOAT / 100, 'closedBy' => $closed === null ? '' : $s['user'], 'overrideBy' => '', 'overrideReason' => '',
            'status' => $open ? 'open' : 'closed', 'varianceTotal' => ($variance + $terminal - $s['card']) / 100,
            'closeNotes' => $variance <= -1000 ? 'Short in the drawer, recounted twice; manager told' : '', 'mode' => 'perCashier',
            'drawerOwnerUserId' => $s['user'], ...$base,
        ], $at, $opened);

        foreach ([['cash', $expected, $open ? 0 : $declared, 0], ['card', $s['card'], $open ? 0 : $s['card'], $terminal]] as [$tender, $exp, $dec, $term]) {
            $push->add($shop, 'ShiftTender', $shop->id("shift-tender|{$id}|{$tender}"), [
                'shiftId' => $id, 'paymentTypeId' => $shop->id("tender|{$tender}"), 'expected' => $exp / 100, 'declared' => $dec / 100,
                'terminalTotal' => $term / 100, 'variance' => $open ? 0 : ($tender === 'cash' ? $variance : $term - $exp) / 100, ...$base,
            ], $at);
        }

        $this->counts($push, $shop, $id, 'open', self::FLOAT, $opened, $s['user'], $base);
        $moves = [['openingFloat', self::FLOAT, $opened, null, 'Float from the safe']];

        if ($paidOut > 0) {
            $moves[] = ['paidOut', -$paidOut, $opened->addMinutes(95), $b->id('reason|paidout-'.['window', 'supplies', 'milk'][$rng->getInt(0, 2)]), 'Receipt in the drawer'];
        }

        if ($drop > 0) {
            $moves[] = ['safeDrop', -$drop, $opened->addMinutes(150), $b->id('reason|safedrop'), 'Bag '.(abs(crc32($id)) % 9000 + 1000)];
        }

        foreach ($moves as $i => [$type, $amount, $when, $reason, $note]) {
            if ($when > $b->now) {
                continue;
            }

            $push->add($shop, 'CashMovement', $shop->id("cash-move|{$id}|{$i}"), [
                'shiftId' => $id, 'userId' => $s['user'], 'type' => $type, 'amount' => $amount / 100, 'reasonId' => $reason ?? '',
                'note' => $note, 'at' => DemoBusiness::iso($when), ...$base,
            ], $when);
        }

        if ($closed !== null) {
            $this->counts($push, $shop, $id, 'close', $declared, $closed, $s['user'], $base);
        }

        return [
            'banked' => $open ? 0 : $declared - self::FLOAT + $drop, 'variance' => $open ? 0 : $variance, 'paidOut' => $paidOut,
            'cardCount' => (int) round($s['count'] * 0.65), 'expected' => $expected, 'declared' => $declared, 'opened' => $opened, 'closed' => $closed,
            'user' => $s['user'], 'card' => $s['card'], 'terminal' => $terminal,
        ];
    }

    /**
     * @param  array<string, string>  $base
     */
    private function counts(DemoPush $push, DemoShop $shop, string $shiftId, string $stage, int $total, CarbonImmutable $at, string $userId, array $base): void
    {
        $left = max(0, $total);

        foreach (self::DENOMINATIONS as $denomination) {
            $count = intdiv($left, $denomination);
            $left -= $count * $denomination;

            if ($count > 0) {
                $push->add($shop, 'CashCount', $shop->id("cash-count|{$shiftId}|{$stage}|{$denomination}"), [
                    'shiftId' => $shiftId, 'stage' => $stage, 'denomination' => $denomination / 100, 'count' => $count, 'userId' => $userId,
                    'at' => DemoBusiness::iso($at), 'total' => $count * $denomination / 100, ...$base,
                ], $at);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $day
     */
    private function zReport(DemoBusiness $b, DemoPush $push, array $day): void
    {
        /** @var DemoShop $shop */
        $shop = $day['shop'];
        [$cash, $declared, $card, $terminal, $start, $end, $lastId, $user] = [0, 0, 0, 0, null, null, '', ''];

        foreach ($day['shifts'] as [$id, $r]) {
            [$cash, $declared, $card, $terminal] = [$cash + $r['expected'], $declared + $r['declared'], $card + $r['card'], $terminal + $r['terminal']];
            $start = $start === null || $r['opened'] < $start ? $r['opened'] : $start;
            [$end, $lastId, $user] = $end === null || $r['closed'] > $end ? [$r['closed'], $id, $r['user']] : [$end, $lastId, $user];
        }

        $tender = fn (string $key, string $name, int $exp, int $dec, int $term, int $var) => [
            'PaymentTypeId' => $shop->id("tender|{$key}"), 'PaymentTypeName' => $name, 'Expected' => $exp / 100, 'Declared' => $dec / 100,
            'TerminalTotal' => $term / 100, 'Variance' => $var / 100, 'ExceedsThreshold' => abs($var) > 1000,
        ];
        $variance = $declared - $cash + $terminal - $card;
        $totals = [
            'Tenders' => [$tender('cash', 'Cash', $cash, $declared, 0, $declared - $cash), $tender('card', 'Card', $card, $card, $terminal, $terminal - $card)],
            'VarianceTotal' => $variance / 100, 'HasVarianceWarning' => abs($variance) > 1000, 'VarianceAlertOver' => 10.0,
        ];
        $ordinal = (int) CarbonImmutable::parse('2024-01-01', 'UTC')->diffInDays(CarbonImmutable::parse($day['day'], 'UTC'));
        $push->add($shop, 'ZReport', $shop->id("z|{$day['register']}|{$day['day']}"), [
            'shiftId' => $lastId, 'registerId' => $day['register'], 'sequenceNo' => $ordinal - 900, 'periodStart' => DemoBusiness::iso($start),
            'periodEnd' => DemoBusiness::iso($end), 'generatedAt' => DemoBusiness::iso($end->addMinutes(2)), 'totalsJson' => (string) json_encode($totals),
            'printedAt' => DemoBusiness::iso($end->addMinutes(2)), 'printedBy' => $user, 'reprintCount' => 0, 'branchId' => $shop->branchId,
        ], $end->addMinutes(2));
    }

    /**
     * The day's takings on a till: cash and card against sales per VAT band and output VAT; cost of sales out of
     * stock; paid-outs and the cash over / short.
     *
     * @param  array<string, mixed>  $day
     * @param  array{bands: array<string, array{net: int, vat: int}>, cost: int}  $takings
     */
    private function journals(DemoJournal $journal, array $day, array $takings): void
    {
        /** @var DemoShop $shop */
        $shop = $day['shop'];
        $net = fn (string $code) => $takings['bands'][$code]['net'] ?? 0;
        $vat = array_sum(array_map(fn (array $band) => $band['vat'], $takings['bands']));
        $sales = $net('S') + $net('R') + $net('Z') + $vat;
        $cashIn = $sales - $day['card'];
        $at = CarbonImmutable::parse($day['day'].' 21:30:00', TradingDay::timezone())->utc();
        $lines = [
            ['1210', max(0, $cashIn), max(0, -$cashIn)], ['1220', max(0, $day['card']), max(0, -$day['card'])],
            ['4000', 0, $net('S')], ['4010', 0, $net('R')], ['4020', 0, $net('Z')], ['2200', 0, $vat],
            ['5000', $takings['cost'], 0], ['1001', 0, $takings['cost']],
            ['7500', $day['paidOut'], 0], ['1210', 0, $day['paidOut'], 'Paid outs'],
            ['8100', max(0, -$day['variance']), max(0, $day['variance'])], ['1210', max(0, $day['variance']), max(0, -$day['variance']), 'Cash over / short'],
        ];
        $journal->post($shop, "takings|{$day['register']}|{$day['day']}", $at, 'ZReport', $shop->id("z|{$day['register']}|{$day['day']}"), "Takings {$shop->branchCode} {$day['day']}", $lines, $shop->cashiers[2]);
    }
}
