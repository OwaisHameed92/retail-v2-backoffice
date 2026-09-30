<?php

namespace App\Domain\Cash\Queries;

use App\Domain\Cash\Support\CashLookup;
use App\Domain\Cash\Support\ZTotals;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Enums\CashCountStage;
use App\Domain\TillData\Enums\CashMovementType;
use App\Domain\TillData\Enums\ShiftStatus;
use App\Domain\TillData\Models\CashCount;
use App\Domain\TillData\Models\CashMovement;
use App\Domain\TillData\Models\PaymentType;
use App\Domain\TillData\Models\Reason;
use App\Domain\TillData\Models\Sale;
use App\Domain\TillData\Models\Shift;
use App\Domain\TillData\Models\ShiftTender;
use App\Domain\TillData\Models\ZReport;
use Illuminate\Support\Collection;

/**
 * One shift (module 5.4), read only, as the till sent it: the float, the per-tender reconciliation (`ShiftTender`:
 * expected, counted, card terminal, variance = counted − expected, negative = short), the drawer ledger
 * (`CashMovement`: paid in / out, drops, account payments…; a movement taken before the shift opened was no-shift
 * cash the till adopted, 0.1.15 §13), the cash counts per stage with expected against counted, the Z report and the
 * sales rung in the shift.
 */
final class ShiftDetail
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Shift $shift): array
    {
        $tenders = ShiftTender::query()->where('shift_id', $shift->id)->get();
        $types = PaymentType::query()->withTrashed()->whereKey($tenders->pluck('payment_type_id')->filter()->unique()->values())->get(['id', 'name', 'is_cash', 'is_card'])->keyBy('id');
        $movements = CashMovement::query()->where('shift_id', $shift->id)->orderBy('at')->orderBy('id')->get();
        $counts = CashCount::query()->where('shift_id', $shift->id)->orderBy('at')->orderBy('denomination', 'desc')->get();
        $z = ZReport::query()->where('shift_id', $shift->id)->orderByDesc('sequence_no')->first();
        $staff = CashLookup::staff([$shift->user_id, $shift->closed_by, $shift->override_by, $shift->drawer_owner_user_id, ...$movements->pluck('user_id'), ...$counts->pluck('user_id')]);
        $reasons = Reason::query()->withTrashed()->whereKey($movements->pluck('reason_id')->filter()->unique()->values())->pluck('text', 'id');
        $cashTenders = $tenders->filter(fn (ShiftTender $t) => (bool) $types->get($t->payment_type_id)?->is_cash);
        $sales = Sale::query()->trading()->where('shift_id', $shift->id);

        return [
            'shift' => [
                'id' => $shift->id,
                'status' => $shift->status?->value,
                'mode' => $shift->mode?->value,
                'shop' => CashLookup::name(CashLookup::shops([$shift->branch_id]), $shift->branch_id),
                'till' => CashLookup::name(CashLookup::tills([$shift->register_id]), $shift->register_id),
                'openedAt' => CashLookup::iso($shift->opened_at),
                'closedAt' => CashLookup::iso($shift->closed_at),
                'openedBy' => CashLookup::name($staff, $shift->user_id),
                'closedBy' => CashLookup::name($staff, $shift->closed_by),
                'drawerOwner' => CashLookup::name($staff, $shift->drawer_owner_user_id),
                'overrideBy' => CashLookup::name($staff, $shift->override_by),
                'overrideReason' => $shift->override_reason !== '' ? $shift->override_reason : null,
                'notes' => $shift->close_notes !== '' ? $shift->close_notes : null,
                'float' => CashLookup::money($shift->opening_float),
                'variance' => $shift->status === ShiftStatus::Closed ? CashLookup::money($shift->variance_total) : null,
                'salesCount' => (clone $sales)->count(),
                'salesTotal' => Money::sum((clone $sales)->pluck('total')),
            ],
            'tenders' => $tenders->map(fn (ShiftTender $t) => [
                'id' => $t->id,
                'name' => $types->get($t->payment_type_id)?->name ?: 'Unknown payment type',
                'cash' => (bool) $types->get($t->payment_type_id)?->is_cash,
                'expected' => CashLookup::money($t->expected),
                'declared' => CashLookup::money($t->declared),
                'terminal' => CashLookup::money($t->terminal_total),
                'variance' => CashLookup::money($t->variance),
            ])->sortBy([['cash', 'desc'], ['name', 'asc']])->values()->all(),
            'movements' => $movements->map(fn (CashMovement $m) => [
                'id' => $m->id,
                'type' => $m->type?->value,
                'at' => CashLookup::iso($m->at),
                'amount' => CashLookup::money($m->amount),
                'reason' => $m->reason_id !== '' ? ($reasons[$m->reason_id] ?? null) : null,
                'note' => $m->note !== '' ? $m->note : null,
                'user' => CashLookup::name($staff, $m->user_id),
                'adopted' => self::before($m, $shift),
            ])->values()->all(),
            'movementTotals' => self::movementTotals($movements),
            'stages' => self::stages($shift, $counts, $cashTenders, $staff),
            'z' => $z === null ? null : ['id' => $z->id, 'sequenceNo' => $z->sequence_no, 'printedAt' => CashLookup::iso($z->printed_at), 'totals' => ZTotals::parse($z->totals_json)->toArray()],
        ];
    }

    /** No-shift cash the till adopted into this shift: taken before the shift opened (0.1.15 §13). */
    private static function before(CashMovement $movement, Shift $shift): bool
    {
        $at = (string) $movement->getRawOriginal('at');
        $opened = (string) $shift->getRawOriginal('opened_at');

        return $at !== '' && $opened !== '' && $at < $opened;
    }

    /**
     * Σ amount per movement type, as sent (the till's sign).
     *
     * @param  Collection<int, CashMovement>  $movements
     * @return list<array{type: string|null, count: int, total: string}>
     */
    private static function movementTotals(Collection $movements): array
    {
        return $movements->groupBy(fn (CashMovement $m) => $m->type->value ?? 'unknown')
            ->map(fn (Collection $group, string $type) => ['type' => $type === 'unknown' ? null : $type, 'count' => $group->count(), 'total' => Money::sum($group->pluck('amount'))])
            ->sortBy(fn (array $row) => array_search(CashMovementType::tryFrom((string) $row['type']), CashMovementType::cases(), true))
            ->values()->all();
    }

    /**
     * Cash counts grouped by stage and time. Expected: the opening float for an opening count, the till's cash
     * expected (cash `ShiftTender.expected`) for the closing count; a spot count has none. Variance = counted −
     * expected (negative = short).
     *
     * @param  Collection<int, CashCount>  $counts
     * @param  Collection<int, ShiftTender>  $cashTenders
     * @param  array<string, string>  $staff
     * @return list<array<string, mixed>>
     */
    private static function stages(Shift $shift, Collection $counts, Collection $cashTenders, array $staff): array
    {
        $groups = $counts->groupBy(fn (CashCount $c) => ($c->stage->value ?? 'unknown').'|'.CashLookup::iso($c->at));
        $closeExpected = $cashTenders->isEmpty() ? null : Money::sum($cashTenders->pluck('expected'));

        return $groups->map(function (Collection $rows) use ($shift, $closeExpected, $staff) {
            /** @var CashCount $first */
            $first = $rows->first();
            $counted = Money::sum($rows->pluck('total'));
            $expected = match ($first->stage) {
                CashCountStage::Open => CashLookup::money($shift->opening_float),
                CashCountStage::Close => $closeExpected,
                default => null,
            };

            return [
                'stage' => $first->stage?->value,
                'at' => CashLookup::iso($first->at),
                'user' => CashLookup::name($staff, $first->user_id),
                'expected' => $expected,
                'counted' => $counted,
                'variance' => $expected === null ? null : Money::sub($counted, $expected),
                'lines' => $rows->map(fn (CashCount $c) => ['denomination' => CashLookup::money($c->denomination), 'count' => $c->count, 'total' => CashLookup::money($c->total)])->values()->all(),
            ];
        })->values()->all();
    }
}
