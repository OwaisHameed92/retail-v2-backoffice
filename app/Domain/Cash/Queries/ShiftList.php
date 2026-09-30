<?php

namespace App\Domain\Cash\Queries;

use App\Domain\Cash\Data\CashFilters;
use App\Domain\Cash\Support\CashLookup;
use App\Domain\Cash\Support\ZTotals;
use App\Domain\Shared\Support\Money;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\TillData\Enums\ShiftStatus;
use App\Domain\TillData\Models\CashMovement;
use App\Domain\TillData\Models\PaymentType;
use App\Domain\TillData\Models\Shift;
use App\Domain\TillData\Models\ShiftTender;
use App\Domain\TillData\Models\ZReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The shifts list (module 5.4): shifts opened in the chosen London days (or every shift open now with
 * `status=open`), newest first, with the till's own figures: float, cash expected / counted / variance from the
 * cash `ShiftTender` lines, the shift's `varianceTotal`, its Z number and the till's alert flag. Nothing is
 * recomputed. The summary adds the open shifts and the no-shift cash still waiting for a shift (0.1.15 §13).
 */
final class ShiftList
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request, CashFilters $filters): array
    {
        $table = TableQuery::from($request)->sortable(['opened_at', 'closed_at', 'variance_total'])->defaultSort('opened_at', 'desc')->defaultPerPage(25);
        $page = $table->paginator(self::query($filters));
        /** @var list<Shift> $shifts */
        $shifts = $page->items();

        return [
            'shifts' => [
                'data' => self::rows($shifts),
                'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage(), 'search' => null, 'sort' => $table->sort(), 'direction' => $table->direction()],
            ],
            'summary' => self::summary($filters),
        ];
    }

    /**
     * @return Builder<Shift>
     */
    public static function query(CashFilters $filters): Builder
    {
        $query = $filters->scope(Shift::query());

        return $filters->status === 'open'
            ? $query->where('status', ShiftStatus::Open->value)
            : $filters->during($query, 'opened_at')->when($filters->status === 'closed', fn (Builder $q) => $q->where('status', ShiftStatus::Closed->value));
    }

    /**
     * @param  list<Shift>  $shifts
     * @return list<array<string, mixed>>
     */
    public static function rows(array $shifts): array
    {
        $ids = array_map(fn (Shift $s) => $s->id, $shifts);
        $lines = $ids === [] ? collect() : ShiftTender::query()->whereIn('shift_id', $ids)->get(['shift_id', 'payment_type_id', 'expected', 'declared', 'variance'])->groupBy('shift_id');
        $cash = self::cashTypeIds();
        $zs = $ids === [] ? collect() : ZReport::query()->whereIn('shift_id', $ids)->get(['shift_id', 'sequence_no', 'totals_json'])->keyBy('shift_id');
        $shops = CashLookup::shops(array_map(fn (Shift $s) => $s->branch_id, $shifts));
        $tills = CashLookup::tills(array_map(fn (Shift $s) => $s->register_id, $shifts));
        $staff = CashLookup::staff(array_merge(array_map(fn (Shift $s) => $s->user_id, $shifts), array_map(fn (Shift $s) => $s->closed_by, $shifts)));

        return array_map(function (Shift $s) use ($lines, $cash, $zs, $shops, $tills, $staff) {
            $cashLines = $lines->get($s->id, collect())->filter(fn (ShiftTender $t) => isset($cash[$t->payment_type_id]));
            $z = $zs->get($s->id);

            return [
                'id' => $s->id,
                'status' => $s->status?->value,
                'openedAt' => CashLookup::iso($s->opened_at),
                'closedAt' => CashLookup::iso($s->closed_at),
                'shop' => CashLookup::name($shops, $s->branch_id),
                'till' => CashLookup::name($tills, $s->register_id),
                'user' => CashLookup::name($staff, $s->user_id),
                'closedBy' => CashLookup::name($staff, $s->closed_by),
                'float' => CashLookup::money($s->opening_float),
                'cashExpected' => $cashLines->isEmpty() ? null : Money::sum($cashLines->pluck('expected')),
                'cashCounted' => $cashLines->isEmpty() ? null : Money::sum($cashLines->pluck('declared')),
                'cashVariance' => $cashLines->isEmpty() ? null : Money::sum($cashLines->pluck('variance')),
                'variance' => $s->status === ShiftStatus::Closed ? CashLookup::money($s->variance_total) : null,
                'z' => $z?->sequence_no,
                'zId' => $z?->id,
                'warning' => $z !== null && ZTotals::parse($z->totals_json)->warning,
            ];
        }, $shifts);
    }

    /**
     * Payment type ids of the business that are cash (`isCash`), as a set.
     *
     * @return array<string, bool>
     */
    public static function cashTypeIds(): array
    {
        return PaymentType::query()->withTrashed()->where('is_cash', true)->pluck('id')->mapWithKeys(fn ($id) => [(string) $id => true])->all();
    }

    /**
     * @return array{shifts: int, open: int, cashVariance: string, short: int, over: int, waitingCount: int, waitingTotal: string}
     */
    private static function summary(CashFilters $filters): array
    {
        $listed = self::query($filters);
        $closed = (clone $listed)->where('status', ShiftStatus::Closed->value);
        $variances = ShiftTender::query()->whereIn('shift_id', (clone $closed)->select('id'))
            ->whereIn('payment_type_id', array_keys(self::cashTypeIds()))->pluck('variance');
        $waiting = $filters->scope(CashMovement::query())->where(fn (Builder $q) => $q->where('shift_id', '')->orWhereNull('shift_id'));

        return [
            'shifts' => (clone $listed)->count(),
            'open' => $filters->scope(Shift::query())->where('status', ShiftStatus::Open->value)->count(),
            'cashVariance' => Money::sum($variances),
            'short' => (clone $closed)->where('variance_total', '<', 0)->count(),
            'over' => (clone $closed)->where('variance_total', '>', 0)->count(),
            'waitingCount' => (clone $waiting)->count(),
            'waitingTotal' => Money::sum((clone $waiting)->pluck('amount')),
        ];
    }
}
