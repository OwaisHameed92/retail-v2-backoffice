<?php

namespace App\Domain\Cash\Queries;

use App\Domain\Cash\Data\CashFilters;
use App\Domain\Cash\Support\CashLookup;
use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Enums\CardSettlementStatus;
use App\Domain\TillData\Enums\ShiftStatus;
use App\Domain\TillData\Models\CardSettlement;
use App\Domain\TillData\Models\PaymentType;
use App\Domain\TillData\Models\Shift;
use App\Domain\TillData\Models\ShiftTender;

/**
 * Card settlement reconciliation per till and trading day (module 5.4). The till's card total is Σ
 * `ShiftTender.expected` of card payment types (`isCard`) of the till's shifts closed that London day; the settled
 * total is Σ `CardSettlement.terminalTotal` of that till's settlements for the day (failed ones left out).
 * Difference = settled − till (negative = less settled than taken). A day is flagged when the two differ, a
 * settlement failed or the till itself marked it mismatched, or card takings have no settlement. Nothing is stored.
 */
final class CardReconciliation
{
    public const PER_PAGE = 25;

    /**
     * @return array<string, mixed>
     */
    public static function for(CashFilters $filters, int $page, int $perPage = self::PER_PAGE): array
    {
        $rows = self::rows($filters);
        $flagged = array_values(array_filter($rows, fn (array $r) => $r['flag'] !== 'matched'));
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);
        $shops = CashLookup::shops(array_column($slice, 'branchId'));
        $tills = CashLookup::tills(array_column($slice, 'registerId'));
        $staff = CashLookup::staff(array_merge(...array_map(fn (array $r) => array_column($r['settlements'], 'settledById'), $slice)));

        return [
            'days' => [
                'data' => array_map(function (array $r) use ($shops, $tills, $staff) {
                    $r['shop'] = CashLookup::name($shops, $r['branchId']);
                    $r['till'] = CashLookup::name($tills, $r['registerId']);
                    $r['settlements'] = array_map(function (array $s) use ($staff) {
                        $s['settledBy'] = CashLookup::name($staff, $s['settledById']);
                        unset($s['settledById']);

                        return $s;
                    }, $r['settlements']);
                    unset($r['branchId']);

                    return $r;
                }, $slice),
                'meta' => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'lastPage' => $lastPage, 'search' => null, 'sort' => null, 'direction' => 'desc'],
            ],
            'summary' => [
                'days' => $total,
                'flagged' => count($flagged),
                'tillCard' => Money::sum(array_filter(array_column($rows, 'tillCard'), fn ($v) => $v !== null)),
                'settled' => Money::sum(array_filter(array_column($rows, 'settled'), fn ($v) => $v !== null)),
                'difference' => Money::sum(array_filter(array_column($rows, 'difference'), fn ($v) => $v !== null)),
            ],
        ];
    }

    /**
     * Every (till, day) of the range with card takings or a settlement, newest day first.
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(CashFilters $filters): array
    {
        $groups = [];
        $blank = fn (string $day, ?string $branch, ?string $register) => ['key' => "{$register}|{$day}", 'day' => $day, 'branchId' => $branch, 'registerId' => $register, 'tillCard' => null, 'settled' => null, 'settlements' => [], 'shifts' => 0];

        $cards = PaymentType::query()->withTrashed()->where('is_card', true)->pluck('id')->all();
        $shifts = $filters->during($filters->scope(Shift::query()), 'closed_at')->where('status', ShiftStatus::Closed->value)->get(['id', 'branch_id', 'register_id', 'closed_at']);
        $lines = $cards === [] || $shifts->isEmpty() ? collect() : ShiftTender::query()->whereIn('shift_id', $shifts->pluck('id'))->whereIn('payment_type_id', $cards)->get(['shift_id', 'expected'])->groupBy('shift_id');

        foreach ($shifts as $shift) {
            $mine = $lines->get($shift->id);

            if ($mine === null || $shift->closed_at === null) {
                continue;
            }

            $day = TradingDay::of($shift->closed_at)[0];
            $key = "{$shift->register_id}|{$day}";
            $groups[$key] ??= $blank($day, $shift->branch_id, $shift->register_id);
            $groups[$key]['tillCard'] = Money::add($groups[$key]['tillCard'] ?? '0', Money::sum($mine->pluck('expected')));
            $groups[$key]['shifts']++;
        }

        foreach ($filters->onDays($filters->scope(CardSettlement::query()))->orderBy('settled_at')->get() as $s) {
            $day = $s->trading_date->format('Y-m-d');
            $key = "{$s->register_id}|{$day}";
            $groups[$key] ??= $blank($day, $s->branch_id, $s->register_id);

            if ($s->status !== CardSettlementStatus::Failed) {
                $groups[$key]['settled'] = Money::add($groups[$key]['settled'] ?? '0', $s->terminal_total);
            }

            $groups[$key]['settlements'][] = [
                'id' => $s->id, 'provider' => $s->provider !== '' ? $s->provider : null, 'reference' => $s->reference !== '' ? $s->reference : null,
                'batch' => $s->batch_reference !== '' ? $s->batch_reference : null, 'terminal' => CashLookup::money($s->terminal_total),
                'pos' => CashLookup::money($s->pos_total), 'variance' => CashLookup::money($s->variance), 'fees' => CashLookup::money($s->fees),
                'transactions' => $s->transaction_count, 'status' => $s->status?->value, 'message' => $s->message !== '' ? $s->message : null,
                'settledAt' => CashLookup::iso($s->settled_at), 'settledById' => $s->settled_by_user_id,
            ];
        }

        $rows = array_map(fn (array $g) => [...$g, ...self::judge($g)], array_values($groups));
        usort($rows, fn (array $a, array $b) => [$b['day'], (string) $a['registerId']] <=> [$a['day'], (string) $b['registerId']]);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $g
     * @return array{difference: string|null, flag: string}
     */
    private static function judge(array $g): array
    {
        $difference = $g['tillCard'] !== null && $g['settled'] !== null ? Money::sub($g['settled'], $g['tillCard']) : null;
        $statuses = array_column($g['settlements'], 'status');

        $flag = match (true) {
            in_array(CardSettlementStatus::Failed->value, $statuses, true) => 'failed',
            $g['settlements'] === [] => Money::isZero($g['tillCard'] ?? '0') ? 'matched' : 'notSettled',
            $g['tillCard'] === null => 'noTillTotal',
            $difference !== null && ! Money::isZero($difference) => 'difference',
            in_array(CardSettlementStatus::Mismatched->value, $statuses, true) => 'mismatched',
            default => 'matched',
        };

        return ['difference' => $difference, 'flag' => $flag];
    }
}
