<?php

namespace App\Domain\Stock\Queries;

use App\Domain\Reporting\Support\Units;
use App\Domain\Shared\Support\Money;
use App\Domain\Stock\Data\StockFilters;
use App\Domain\TillData\Models\StockTake;
use App\Domain\TillData\Models\StockTakeLine;
use App\Domain\TillData\Models\TillUser;
use App\Domain\TillData\Queries\TillSum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The tills' stock takes (module 5.1): `StockTake` and its lines are branch-owned (ownership.json), so they are
 * started, counted and approved on the till and only read here. Variances are the till's stored figures
 * (`StockTakeLine.varianceQty` / `varianceCost`), added up exactly.
 */
final class StockTakes
{
    public const STATUSES = ['draft', 'counting', 'review', 'approved', 'cancelled'];

    /**
     * One page of stock takes, newest first, with their line totals.
     *
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, perPage: int, total: int}}
     */
    public static function page(StockFilters $f, ?string $status, int $page, int $perPage): array
    {
        $query = StockTake::query()
            ->when($f->shop !== null, fn (Builder $q) => $q->where('branch_id', $f->shop))
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status));
        $total = (clone $query)->count();
        $takes = $query->orderByDesc('started_at')->orderByDesc('id')->offset(($page - 1) * $perPage)->limit($perPage)->get();
        $ids = $takes->pluck('id')->all();
        $companyId = $takes->first()?->company_id;

        $totals = $ids === [] ? collect() : DB::table('stock_take_lines')->where('company_id', $companyId)->whereIn('stock_take_id', $ids)
            ->whereNull('deleted_at')->groupBy('stock_take_id')->select('stock_take_id')
            ->selectRaw('COUNT(*) as lines_count, SUM(CASE WHEN counted_qty IS NOT NULL THEN 1 ELSE 0 END) as counted')
            ->addSelect(Units::sum('COALESCE(variance_qty, 0)', 4, 'qty_units'))
            ->addSelect(Units::sum('COALESCE(variance_cost, 0)', 4, 'cost_units'))
            ->get()->keyBy('stock_take_id');
        $shops = StockNames::shops();
        $staff = TillUser::query()->whereIn('id', $takes->pluck('started_by_user_id')->filter()->unique()->all())->pluck('name', 'id');

        return [
            'data' => $takes->map(function (StockTake $t) use ($totals, $shops, $staff) {
                $sum = $totals[$t->id] ?? null;

                return [
                    ...self::header($t, $shops, $staff->all()),
                    'lines' => (int) ($sum->lines_count ?? 0),
                    'counted' => (int) ($sum->counted ?? 0),
                    'varianceQty' => Units::decimal(Units::of($sum->qty_units ?? null), 4),
                    'varianceCost' => Money::round(Units::decimal(Units::of($sum->cost_units ?? null), 4), 2),
                ];
            })->values()->all(),
            'meta' => ['page' => $page, 'perPage' => $perPage, 'total' => $total],
        ];
    }

    /**
     * A stock take with one page of its lines (`variance` = only lines counted with a difference) and its totals.
     *
     * @return array<string, mixed>
     */
    public static function detail(StockTake $take, bool $varianceOnly, int $page, int $perPage): array
    {
        $lines = StockTakeLine::query()->where('stock_take_id', $take->id);
        $sums = TillSum::many($lines, ['variance_qty' => 4, 'variance_cost' => 4]);
        $gains = TillSum::of($lines->clone()->where('variance_cost', '>', 0), 'variance_cost', 4);
        $losses = TillSum::of($lines->clone()->where('variance_cost', '<', 0), 'variance_cost', 4);
        $snapshotValue = DB::query()->fromSub($lines->clone()->toBase()->reorder()
            ->selectRaw('ROUND(COALESCE(snapshot_qty, 0) * COALESCE(unit_cost, 0) * 10000) as u'), 'x')->sum('u');

        $filtered = $lines->clone()->when($varianceOnly, fn (Builder $q) => $q->whereNotNull('counted_qty')->where('variance_qty', '!=', 0));
        $total = (clone $filtered)->count();
        $rows = $filtered->orderBy('product_name')->orderBy('id')->offset(($page - 1) * $perPage)->limit($perPage)->get();
        $staff = TillUser::query()->whereIn('id', array_filter([$take->started_by_user_id, $take->approved_by_user_id, ...$rows->pluck('counted_by_user_id')->all()]))
            ->pluck('name', 'id')->all();

        return [
            'take' => [
                ...self::header($take, StockNames::shops(), $staff),
                'approvedBy' => $staff[$take->approved_by_user_id] ?? null,
                'isBlind' => (bool) $take->is_blind,
                'countUncountedAsZero' => (bool) $take->count_uncounted_as_zero,
                'note' => (string) ($take->note ?? '') !== '' ? $take->note : null,
                'lines' => $lines->count(),
                'counted' => $lines->clone()->whereNotNull('counted_qty')->count(),
                'recount' => $lines->clone()->where('recount_required', true)->count(),
                'varianceQty' => $sums['variance_qty'],
                'varianceCost' => Money::round($sums['variance_cost'], 2),
                'gains' => Money::round($gains, 2),
                'losses' => Money::round($losses, 2),
                'snapshotValue' => Money::round(Units::decimal(Units::of($snapshotValue), 4), 2),
            ],
            'lines' => [
                'data' => $rows->map(fn (StockTakeLine $l) => [
                    'id' => $l->id,
                    'productId' => $l->product_id,
                    'name' => (string) $l->product_name !== '' ? $l->product_name : 'Unknown product',
                    'expected' => Money::normalise($l->snapshot_qty ?? 0, 4),
                    'counted' => $l->counted_qty !== null ? Money::normalise($l->counted_qty, 4) : null,
                    'varianceQty' => Money::normalise($l->variance_qty ?? 0, 4),
                    'unitCost' => Money::normalise($l->unit_cost ?? 0, 4),
                    'varianceCost' => Money::round(Money::normalise($l->variance_cost ?? 0, 4), 2),
                    'recount' => (bool) $l->recount_required,
                    'countedBy' => $staff[$l->counted_by_user_id] ?? null,
                    'countedAt' => $l->counted_at?->toIso8601ZuluString(),
                ])->values()->all(),
                'meta' => ['page' => $page, 'perPage' => $perPage, 'total' => $total],
            ],
        ];
    }

    /**
     * @param  array<string, string>  $shops
     * @param  array<string, mixed>  $staff
     * @return array<string, mixed>
     */
    private static function header(StockTake $t, array $shops, array $staff): array
    {
        return [
            'id' => $t->id,
            'reference' => (string) $t->reference,
            'name' => (string) $t->name,
            'shop' => $shops[$t->branch_id] ?? 'Unknown shop',
            'scope' => $t->scope?->value,
            'status' => $t->status?->value,
            'startedAt' => $t->started_at->toIso8601ZuluString(),
            'approvedAt' => $t->approved_at?->toIso8601ZuluString(),
            'cancelledAt' => $t->cancelled_at?->toIso8601ZuluString(),
            'startedBy' => isset($staff[$t->started_by_user_id]) ? (string) $staff[$t->started_by_user_id] : null,
            'isHighValue' => (bool) $t->is_high_value_count,
        ];
    }
}
