<?php

namespace App\Domain\Stock\Queries;

use App\Domain\Reporting\Support\Units;
use App\Domain\Shared\Support\Money;
use App\Domain\Stock\Data\StockFilters;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Stock on hand (module 5.1). One shop: a row per stock line. Every shop: a row per product with the quantities of
 * all its shops added up and how many shops are low, out or negative. Value at cost = quantity on hand × the
 * product's cost price (as the stock report, 4.8); sums are exact (`Units`, never floats).
 *
 * Status filters: `low` (at or below the low-stock point, out of stock included), `out` (none or less), `negative`
 * (below zero). Every shop: a product matches when any of its shops does. Order: worst first, then name.
 */
final class StockOnHand
{
    public function __construct(private readonly StockLines $lines) {}

    /** Whether any till of the business has sent stock lines at all. */
    public function hasAny(string $companyId): bool
    {
        return DB::table('branch_products')->where('company_id', $companyId)->whereNull('deleted_at')->exists();
    }

    /**
     * Totals of the filtered lines, the status filter left out (so the counts can label the status tabs).
     *
     * @return array{lines: int, products: int, units: string, value: string, costed: int, low: int, out: int, negative: int}
     */
    public function summary(string $companyId, StockFilters $f): array
    {
        [$t, $bindings] = $this->lines->threshold($companyId);
        $row = $this->lines->base($companyId, $f)->selectRaw(
            'COUNT(*) as lines_count, COUNT(DISTINCT bp.product_id) as products, '
            .'SUM(ROUND(COALESCE(bp.qty_on_hand, 0) * 10000)) as units, '
            .'SUM(ROUND(COALESCE(bp.qty_on_hand, 0) * COALESCE(p.cost_price, 0) * 10000)) as value_units, '
            .'SUM(CASE WHEN COALESCE(p.cost_price, 0) > 0 THEN 1 ELSE 0 END) as costed, '
            .'SUM(CASE WHEN '.StockLines::condition('low', $t).' THEN 1 ELSE 0 END) as low, '
            .'SUM(CASE WHEN COALESCE(bp.qty_on_hand, 0) <= 0 THEN 1 ELSE 0 END) as out_count, '
            .'SUM(CASE WHEN COALESCE(bp.qty_on_hand, 0) < 0 THEN 1 ELSE 0 END) as negative',
            $bindings,
        )->first();

        return [
            'lines' => (int) ($row->lines_count ?? 0),
            'products' => (int) ($row->products ?? 0),
            'units' => Units::decimal(Units::of($row->units ?? null), 4),
            'value' => Money::round(Units::decimal(Units::of($row->value_units ?? null), 4), 2),
            'costed' => (int) ($row->costed ?? 0),
            'low' => (int) ($row->low ?? 0),
            'out' => (int) ($row->out_count ?? 0),
            'negative' => (int) ($row->negative ?? 0),
        ];
    }

    /**
     * One page of lines (one shop) or products (every shop).
     *
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    public function page(string $companyId, StockFilters $f, int $page, int $perPage): array
    {
        return $f->shop !== null ? $this->shopLines($companyId, $f, $page, $perPage) : $this->products($companyId, $f, $page, $perPage);
    }

    /** @return array{total: int, rows: list<array<string, mixed>>} */
    private function shopLines(string $companyId, StockFilters $f, int $page, int $perPage): array
    {
        [$t, $bindings] = $this->lines->threshold($companyId);
        $query = $this->lines->base($companyId, $f)
            ->when($f->status !== null, fn (Builder $q) => $q->whereRaw(StockLines::condition((string) $f->status, $t), $f->status === 'low' ? $bindings : []));
        $total = (clone $query)->count();

        $rows = $query
            ->leftJoin('departments as d', fn ($j) => $j->on('d.id', '=', 'p.department_id')->on('d.company_id', '=', 'p.company_id'))
            ->select(['bp.id', 'bp.product_id', 'bp.branch_id', 'p.name', 'p.sku', 'd.name as department', 'bp.qty_on_hand', 'bp.qty_reserved',
                'bp.qty_available', 'bp.min_qty', 'bp.max_qty', 'p.max_stock_qty', 'p.cost_price'])
            ->selectRaw("{$t} as threshold", $bindings)
            ->orderByRaw("CASE WHEN COALESCE(bp.qty_on_hand, 0) < 0 THEN 0 WHEN COALESCE(bp.qty_on_hand, 0) <= 0 THEN 1 WHEN COALESCE(bp.qty_on_hand, 0) <= {$t} THEN 2 ELSE 3 END", $bindings)
            ->orderBy('p.name')->orderBy('bp.id')
            ->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return ['total' => $total, 'rows' => $rows->map(function (object $r) {
            $onHand = Money::normalise($r->qty_on_hand ?? 0, 4);
            $threshold = Money::normalise($r->threshold ?? 0, 4);
            $cost = Money::normalise($r->cost_price ?? 0, 4);
            $max = $r->max_qty ?? $r->max_stock_qty;

            return [
                'id' => (string) $r->id,
                'productId' => (string) $r->product_id,
                'shopId' => (string) $r->branch_id,
                'name' => self::name($r->name),
                'sku' => (string) ($r->sku ?? ''),
                'department' => $r->department !== null ? (string) $r->department : null,
                'onHand' => $onHand,
                'reserved' => Money::normalise($r->qty_reserved ?? 0, 4),
                'available' => $r->qty_available !== null ? Money::normalise($r->qty_available, 4) : Money::sub($onHand, $r->qty_reserved ?? 0, 4),
                'lowAt' => $threshold,
                'max' => $max !== null ? Money::normalise($max, 4) : null,
                'cost' => Money::isZero($cost) ? null : $cost,
                'value' => Money::isZero($cost) ? null : Money::round(Money::mul($onHand, $cost, 4), 2),
                'status' => self::status($onHand, $threshold),
            ];
        })->values()->all()];
    }

    /** @return array{total: int, rows: list<array<string, mixed>>} */
    private function products(string $companyId, StockFilters $f, int $page, int $perPage): array
    {
        [$t, $bindings] = $this->lines->threshold($companyId);
        $low = 'SUM(CASE WHEN '.StockLines::condition('low', $t).' THEN 1 ELSE 0 END)';
        $out = 'SUM(CASE WHEN COALESCE(bp.qty_on_hand, 0) <= 0 THEN 1 ELSE 0 END)';
        $negative = 'SUM(CASE WHEN COALESCE(bp.qty_on_hand, 0) < 0 THEN 1 ELSE 0 END)';

        $query = $this->lines->base($companyId, $f)->groupBy('p.id')
            ->when($f->status !== null, fn (Builder $q) => $q->havingRaw(match ($f->status) {
                'negative' => $negative,
                'out' => $out,
                default => $low,
            }.' > 0', $f->status === 'low' ? $bindings : []));
        $total = DB::query()->fromSub((clone $query)->select('p.id'), 'grouped')->count();

        $rows = $query
            ->select(['p.id', DB::raw('MAX(p.name) as name'), DB::raw('MAX(p.sku) as sku'), DB::raw('MAX(p.cost_price) as cost_price'),
                DB::raw('MAX(p.department_id) as department_id'), DB::raw('COUNT(*) as shops')])
            ->selectRaw('SUM(ROUND(COALESCE(bp.qty_on_hand, 0) * 10000)) as units')
            ->selectRaw('SUM(ROUND(COALESCE(bp.qty_reserved, 0) * 10000)) as reserved_units')
            ->selectRaw("{$low} as low_shops", $bindings)
            ->selectRaw("{$out} as out_shops, {$negative} as negative_shops")
            ->orderByRaw("CASE WHEN {$negative} > 0 THEN 0 WHEN {$out} > 0 THEN 1 WHEN {$low} > 0 THEN 2 ELSE 3 END", $bindings)
            ->orderByRaw('MAX(p.name)')->orderBy('p.id')
            ->offset(($page - 1) * $perPage)->limit($perPage)->get();

        $departments = DB::table('departments')->where('company_id', $companyId)
            ->whereIn('id', $rows->pluck('department_id')->filter()->unique()->all())->pluck('name', 'id');

        return ['total' => $total, 'rows' => $rows->map(function (object $r) use ($departments) {
            $onHand = Units::decimal(Units::of($r->units), 4);
            $cost = Money::normalise($r->cost_price ?? 0, 4);
            [$low, $out, $negative] = [(int) $r->low_shops, (int) $r->out_shops, (int) $r->negative_shops];

            return [
                'id' => (string) $r->id,
                'productId' => (string) $r->id,
                'shopId' => null,
                'name' => self::name($r->name),
                'sku' => (string) ($r->sku ?? ''),
                'department' => isset($departments[$r->department_id]) ? (string) $departments[$r->department_id] : null,
                'onHand' => $onHand,
                'reserved' => Units::decimal(Units::of($r->reserved_units), 4),
                'shops' => (int) $r->shops,
                'lowShops' => $low,
                'outShops' => $out,
                'negativeShops' => $negative,
                'cost' => Money::isZero($cost) ? null : $cost,
                'value' => Money::isZero($cost) ? null : Money::round(Money::mul($onHand, $cost, 4), 2),
                'status' => $negative > 0 ? 'negative' : ($out > 0 ? 'out' : ($low > 0 ? 'low' : 'ok')),
            ];
        })->values()->all()];
    }

    public static function status(string $onHand, string $threshold): string
    {
        return match (true) {
            Money::compare($onHand, '0') < 0 => 'negative',
            Money::compare($onHand, '0') === 0 => 'out',
            Money::compare($onHand, $threshold) <= 0 => 'low',
            default => 'ok',
        };
    }

    private static function name(mixed $name): string
    {
        return (string) ($name ?? '') !== '' ? (string) $name : 'Unknown product';
    }
}
