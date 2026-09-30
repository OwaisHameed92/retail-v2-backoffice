<?php

namespace App\Domain\Stock\Queries;

use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Reporting\Support\Units;
use App\Domain\Shared\Support\Money;
use App\Domain\Stock\Data\StockFilters;
use App\Domain\Stock\Support\MovementKinds;
use App\Domain\TillData\Models\DateCheck;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\StockLayer;
use App\Domain\TillData\Models\TillUser;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dates and wastage (module 5.1): the tills' batches (`StockLayer`: what is left of a delivery, with its batch number
 * and best-before date) that are out of date or near it, the tills' date checks (`DateCheck`: ok, reduced or wasted)
 * and the stock written off (wastage, damaged, out of date, theft movements) over the chosen days. All read only.
 */
final class ExpiryList
{
    public const CHECKS = 50;

    /** @return array<string, mixed> */
    public static function for(StockFilters $f, int $page, int $perPage): array
    {
        $today = TradingDay::today()->format('Y-m-d');
        $limit = TradingDay::today()->addDays($f->within)->format('Y-m-d');
        $batches = self::batches($f)
            ->when($f->within === 0, fn (Builder $q) => $q->where('expiry_date', '<', $today), fn (Builder $q) => $q->where('expiry_date', '<=', $limit));
        $total = (clone $batches)->count();
        $rows = $batches->orderBy('expiry_date')->orderBy('id')->offset(($page - 1) * $perPage)->limit($perPage)->get();
        $products = Product::query()->whereIn('id', $rows->pluck('product_id')->unique()->all())->get(['id', 'name', 'sku'])->keyBy('id');
        $shops = StockNames::shops();

        return [
            'batches' => [
                'data' => $rows->map(function (StockLayer $l) use ($products, $shops, $today) {
                    $expiry = $l->expiry_date?->format('Y-m-d');
                    $product = $products[$l->product_id] ?? null;

                    return [
                        'id' => $l->id,
                        'productId' => $l->product_id,
                        'name' => $product !== null && $product->name !== '' ? $product->name : 'Unknown product',
                        'sku' => (string) ($product->sku ?? ''),
                        'shop' => $shops[$l->branch_id] ?? 'Unknown shop',
                        'batch' => $l->batch_no,
                        'expiry' => $expiry,
                        'daysLeft' => $expiry !== null ? (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($expiry), false) : null,
                        'qty' => Money::normalise($l->qty_remaining ?? 0, 4),
                        'unitCost' => Money::normalise($l->unit_cost ?? 0, 4),
                        'value' => Money::round(Money::mul($l->qty_remaining ?? 0, $l->unit_cost ?? 0, 4), 2),
                        'expired' => $expiry !== null && $expiry < $today,
                    ];
                })->values()->all(),
                'meta' => ['page' => $page, 'perPage' => $perPage, 'total' => $total],
            ],
            'buckets' => [
                'expired' => self::bucket(self::batches($f)->where('expiry_date', '<', $today)),
                'week' => self::bucket(self::batches($f)->where('expiry_date', '>=', $today)->where('expiry_date', '<=', TradingDay::today()->addDays(7)->format('Y-m-d'))),
                'month' => self::bucket(self::batches($f)->where('expiry_date', '>=', $today)->where('expiry_date', '<=', TradingDay::today()->addDays(30)->format('Y-m-d'))),
            ],
            'checks' => self::checks($f),
            'wastage' => self::wastage($f),
        ];
    }

    /** @return Builder<StockLayer> */
    private static function batches(StockFilters $f): Builder
    {
        return StockLayer::query()->whereNotNull('expiry_date')->where('qty_remaining', '>', 0)
            ->when($f->shop !== null, fn (Builder $q) => $q->where('branch_id', $f->shop))
            ->when($f->product !== null, fn (Builder $q) => $q->where('product_id', $f->product));
    }

    /**
     * @param  Builder<StockLayer>  $query
     * @return array{count: int, qty: string, value: string}
     */
    private static function bucket(Builder $query): array
    {
        $row = $query->toBase()->selectRaw('COUNT(*) as n')
            ->addSelect(Units::sum('COALESCE(qty_remaining, 0)', 4, 'qty_units'))
            ->addSelect(Units::sum('COALESCE(qty_remaining, 0) * COALESCE(unit_cost, 0)', 4, 'value_units'))->first();

        return [
            'count' => (int) ($row->n ?? 0),
            'qty' => Units::decimal(Units::of($row->qty_units ?? null), 4),
            'value' => Money::round(Units::decimal(Units::of($row->value_units ?? null), 4), 2),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function checks(StockFilters $f): array
    {
        [$start] = TradingDay::window((string) $f->from);
        [, $end] = TradingDay::window((string) $f->to);
        $rows = DateCheck::query()->where('checked_at', '>=', $start->format('Y-m-d H:i:s'))->where('checked_at', '<', $end->format('Y-m-d H:i:s'))
            ->when($f->shop !== null, fn (Builder $q) => $q->where('branch_id', $f->shop))
            ->when($f->product !== null, fn (Builder $q) => $q->where('product_id', $f->product))
            ->orderByDesc('checked_at')->orderByDesc('id')->limit(self::CHECKS)->get();
        $products = Product::query()->whereIn('id', $rows->pluck('product_id')->unique()->all())->pluck('name', 'id');
        $staff = TillUser::query()->whereIn('id', $rows->pluck('checked_by_user_id')->filter()->unique()->all())->pluck('name', 'id');
        $shops = StockNames::shops();

        return $rows->map(fn (DateCheck $c) => [
            'id' => $c->id,
            'productId' => $c->product_id,
            'name' => (string) ($products[$c->product_id] ?? '') !== '' ? (string) $products[$c->product_id] : 'Unknown product',
            'shop' => $shops[$c->branch_id] ?? 'Unknown shop',
            'action' => $c->action?->value,
            'markdownPercent' => $c->markdown_percent !== null ? Money::normalise($c->markdown_percent, 2) : null,
            'note' => (string) ($c->note ?? '') !== '' ? $c->note : null,
            'checkedAt' => $c->checked_at->toIso8601ZuluString(),
            'checkedBy' => $staff[$c->checked_by_user_id] ?? null,
        ])->values()->all();
    }

    /**
     * Stock written off over the days, by movement type: units (positive = lost) and value at the movement's cost.
     *
     * @return array{rows: list<array{type: string, label: string, count: int, qty: string, value: string}>, qty: string, value: string}
     */
    private static function wastage(StockFilters $f): array
    {
        $rows = MovementSearch::query(new StockFilters(shop: $f->shop, product: $f->product, type: 'wastage', from: $f->from, to: $f->to))
            ->toBase()->groupBy('stock_movements.type')->select('stock_movements.type')->selectRaw('COUNT(*) as n')
            ->addSelect(Units::sum('-COALESCE(stock_movements.qty_delta, 0)', 4, 'qty_units'))
            ->addSelect(Units::sum('-COALESCE(stock_movements.qty_delta, 0) * COALESCE(stock_movements.unit_cost, 0)', 4, 'value_units'))
            ->get()->keyBy('type');
        $out = [];
        [$qty, $value] = ['0', '0'];

        foreach (MovementKinds::WASTAGE as $type) {
            if (isset($rows[$type])) {
                $r = $rows[$type];
                $qty = Units::add($qty, Units::of($r->qty_units));
                $value = Units::add($value, Units::of($r->value_units));
                $out[] = [
                    'type' => $type,
                    'label' => MovementKinds::label($type),
                    'count' => (int) $r->n,
                    'qty' => Units::decimal(Units::of($r->qty_units), 4),
                    'value' => Money::round(Units::decimal(Units::of($r->value_units), 4), 2),
                ];
            }
        }

        return ['rows' => $out, 'qty' => Units::decimal($qty, 4), 'value' => Money::round(Units::decimal($value, 4), 2)];
    }
}
