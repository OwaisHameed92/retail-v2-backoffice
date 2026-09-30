<?php

namespace App\Domain\Stock\Queries;

use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Reporting\Support\Units;
use App\Domain\Shared\Support\Money;
use App\Domain\Stock\Data\StockFilters;
use App\Domain\Stock\Support\MovementKinds;
use App\Domain\TillData\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The tills' stock movements (module 5.1; the till's stock ledger, read only) for the filters: shop, product, kind
 * and London days. Newest first by (at, id), paged by keyset on the `(company_id[, branch_id|product_id], at)`
 * indexes, never OFFSET.
 */
final class MovementSearch
{
    /** @return Builder<StockMovement> */
    public static function query(StockFilters $f): Builder
    {
        [$start] = TradingDay::window((string) $f->from);
        [, $end] = TradingDay::window((string) $f->to);

        return StockMovement::query()
            ->where('stock_movements.at', '>=', $start->format('Y-m-d H:i:s'))
            ->where('stock_movements.at', '<', $end->format('Y-m-d H:i:s'))
            ->when($f->shop !== null, fn (Builder $q) => $q->where('stock_movements.branch_id', $f->shop))
            ->when($f->product !== null, fn (Builder $q) => $q->where('stock_movements.product_id', $f->product))
            ->when($f->type !== null, fn (Builder $q) => $q->whereIn('stock_movements.type', MovementKinds::GROUPS[$f->type] ?? []));
    }

    /**
     * One page, newest first. `$after` = older than that row, `$before` = newer than that row.
     *
     * @param  Builder<StockMovement>  $query
     * @return array{rows: Collection<int, StockMovement>, older: string|null, newer: string|null}
     */
    public static function page(Builder $query, ?string $after, ?string $before, int $perPage): array
    {
        $cursor = self::decode($before) ?? self::decode($after);
        $backwards = self::decode($before) !== null;
        $direction = $backwards ? 'asc' : 'desc';
        $op = $backwards ? '>' : '<';

        $rows = $query->clone()
            ->when($cursor !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('stock_movements.at', $op, $cursor[0])
                ->orWhere(fn (Builder $same) => $same->where('stock_movements.at', $cursor[0])->where('stock_movements.id', $op, $cursor[1]))))
            ->orderBy('stock_movements.at', $direction)->orderBy('stock_movements.id', $direction)
            ->limit($perPage + 1)->get();

        $more = $rows->count() > $perPage;
        $rows = $rows->take($perPage);

        if ($backwards) {
            $rows = $rows->reverse()->values();
        }

        $first = $rows->first();
        $last = $rows->last();

        return [
            'rows' => $rows,
            'older' => $last !== null && ($backwards || $more) ? self::encode($last) : null,
            'newer' => $first !== null && ($backwards ? $more : $cursor !== null) ? self::encode($first) : null,
        ];
    }

    /**
     * Net quantity and value (quantity × the movement's unit cost) per kind group for the filters.
     *
     * @param  Builder<StockMovement>  $query
     * @return list<array{group: string, label: string, count: int, qty: string, value: string}>
     */
    public static function summary(Builder $query): array
    {
        $rows = $query->clone()->toBase()->groupBy('stock_movements.type')
            ->select('stock_movements.type')
            ->selectRaw('COUNT(*) as n')
            ->addSelect(Units::sum('COALESCE(stock_movements.qty_delta, 0)', 4, 'qty_units'))
            ->addSelect(Units::sum('COALESCE(stock_movements.qty_delta, 0) * COALESCE(stock_movements.unit_cost, 0)', 4, 'value_units'))
            ->get();

        $groups = [];

        foreach ($rows as $r) {
            $group = MovementKinds::group($r->type) ?? 'adjustments';
            $groups[$group] ??= ['n' => 0, 'qty' => '0', 'value' => '0'];
            $groups[$group]['n'] += (int) $r->n;
            $groups[$group]['qty'] = Units::add($groups[$group]['qty'], Units::of($r->qty_units));
            $groups[$group]['value'] = Units::add($groups[$group]['value'], Units::of($r->value_units));
        }

        $out = [];

        foreach (array_keys(MovementKinds::GROUPS) as $group) {
            if (isset($groups[$group])) {
                $out[] = [
                    'group' => $group,
                    'label' => MovementKinds::GROUP_LABELS[$group],
                    'count' => $groups[$group]['n'],
                    'qty' => Units::decimal($groups[$group]['qty'], 4),
                    'value' => Money::round(Units::decimal($groups[$group]['value'], 4), 2),
                ];
            }
        }

        return $out;
    }

    public static function encode(StockMovement $m): string
    {
        return rtrim(strtr(base64_encode($m->getRawOriginal('at').'|'.$m->id), '+/', '-_'), '=');
    }

    /** @return array{0: string, 1: string}|null */
    private static function decode(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '' || strlen($cursor) > 120) {
            return null;
        }

        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($raw === false || preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?)\|([0-9A-Za-z]{26})$/', $raw, $m) !== 1) {
            return null;
        }

        return [$m[1], $m[2]];
    }
}
