<?php

namespace App\Domain\Sales\Queries;

use App\Domain\Sales\Data\SaleFilters;
use App\Domain\TillData\Enums\SaleStatus;
use App\Domain\TillData\Enums\SaleType;
use App\Domain\TillData\Models\Customer;
use App\Domain\TillData\Models\Sale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The filtered sales of the current company, built for millions of rows (module 4.6):
 *
 * - finished documents only: completed or voided, of a trading type (quotes, training, open and held baskets never);
 * - newest first by (trading_day, id) — ids are the till's ULIDs, so this is the order baskets were started — paged
 *   by keyset on the `(company_id[, branch_id], trading_day)` indexes, never OFFSET;
 * - counted up to a cap only ("10,000+"), never COUNT(*) over the whole set.
 */
final class SaleSearch
{
    public const COUNT_CAP = 10_000;

    /** Customers a name / card / phone text may match at most (their sales are then listed). */
    private const CUSTOMER_MATCHES = 200;

    /** @return Builder<Sale> */
    public static function query(SaleFilters $f): Builder
    {
        $trading = array_map(fn (SaleType $t) => $t->value, Sale::TRADING_TYPES);

        return Sale::query()
            ->whereIn('sales.status', [SaleStatus::Completed->value, SaleStatus::Voided->value])
            ->whereIn('sales.type', $trading)
            ->when($f->receiptPrefix() !== null,
                fn (Builder $q) => $q->where('sales.receipt_number', 'like', self::escape((string) $f->receiptPrefix()).'%')->whereNotNull('sales.trading_day'),
                fn (Builder $q) => $q->whereBetween('sales.trading_day', [$f->from, $f->to]))
            ->when($f->saleNumber() !== null, fn (Builder $q) => $q->where('sales.number', $f->saleNumber()))
            ->when($f->shop !== null, fn (Builder $q) => $q->where('sales.branch_id', $f->shop))
            ->when($f->till !== null, fn (Builder $q) => $q->where('sales.register_id', $f->till))
            ->when($f->staff !== null, fn (Builder $q) => $q->where('sales.user_id', $f->staff))
            ->when($f->status === 'completed', fn (Builder $q) => $q->where('sales.status', SaleStatus::Completed->value)->where('sales.type', '!=', SaleType::Refund->value))
            ->when($f->status === 'refunds', fn (Builder $q) => $q->where('sales.status', SaleStatus::Completed->value)->where('sales.type', SaleType::Refund->value))
            ->when($f->status === 'voided', fn (Builder $q) => $q->where('sales.status', SaleStatus::Voided->value))
            ->when($f->payment !== null, fn (Builder $q) => $q->whereExists(fn (QueryBuilder $e) => $e->from('sale_payments as p')
                ->whereColumn('p.sale_id', 'sales.id')->whereColumn('p.company_id', 'sales.company_id')->whereNull('p.deleted_at')
                ->whereRaw('LOWER(p.payment_type_name) = ?', [mb_strtolower((string) $f->payment)])))
            ->when($f->min !== null, fn (Builder $q) => $q->whereRaw('ABS(sales.total) >= CAST(? AS DECIMAL(12,2))', [$f->min]))
            ->when($f->max !== null, fn (Builder $q) => $q->whereRaw('ABS(sales.total) <= CAST(? AS DECIMAL(12,2))', [$f->max]))
            ->when($f->customer !== null, fn (Builder $q) => SaleFilters::isId($f->customer)
                ? $q->where('sales.customer_id', $f->customer)
                : $q->whereIn('sales.customer_id', self::customerIds((string) $f->customer)));
    }

    /**
     * One page, newest first. `$after` = older than that row, `$before` = newer than that row.
     *
     * @param  Builder<Sale>  $query
     * @return array{rows: Collection<int, Sale>, older: string|null, newer: string|null}
     */
    public static function page(Builder $query, ?string $after, ?string $before, int $perPage): array
    {
        $cursor = self::decode($before) ?? self::decode($after);
        $backwards = self::decode($before) !== null;
        $direction = $backwards ? 'asc' : 'desc';
        $op = $backwards ? '>' : '<';

        $rows = $query->clone()
            ->when($cursor !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('sales.trading_day', $op, $cursor[0])
                ->orWhere(fn (Builder $same) => $same->where('sales.trading_day', $cursor[0])->where('sales.id', $op, $cursor[1]))))
            ->orderBy('sales.trading_day', $direction)->orderBy('sales.id', $direction)
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
     * How many sales match, counting at most `$cap + 1` rows (the screen shows "10,000+").
     *
     * @param  Builder<Sale>  $query
     */
    public static function countUpTo(Builder $query, int $cap = self::COUNT_CAP): int
    {
        return DB::query()->fromSub($query->clone()->toBase()->reorder()->select('sales.id')->limit($cap + 1), 'capped')->count();
    }

    /**
     * Walk every matching sale newest first in chunks (exports), without OFFSET.
     *
     * @param  Builder<Sale>  $query
     * @param  callable(Collection<int, Sale>): void  $each
     */
    public static function chunk(Builder $query, int $size, callable $each): void
    {
        $after = null;

        do {
            $page = self::page($query, $after, null, $size);

            if ($page['rows']->isNotEmpty()) {
                $each($page['rows']);
            }

            $after = $page['older'];
        } while ($after !== null);
    }

    public static function encode(Sale $sale): string
    {
        return rtrim(strtr(base64_encode($sale->getAttribute('trading_day').'|'.$sale->id), '+/', '-_'), '=');
    }

    /** @return array{0: string, 1: string}|null */
    private static function decode(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($raw === false || preg_match('/^(\d{4}-\d{2}-\d{2})\|([0-9A-Za-z]{26})$/', $raw, $m) !== 1) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    /** @return list<string> */
    private static function customerIds(string $term): array
    {
        $digits = preg_replace('/\D+/', '', $term) ?? '';

        return Customer::query()->where(function (Builder $q) use ($term, $digits) {
            $q->where('name', 'like', '%'.self::escape($term).'%')->orWhere('card_no', strtoupper($term));

            if (strlen($digits) >= 4) {
                $q->orWhere('phone', 'like', self::escape($term).'%');
            }
        })->limit(self::CUSTOMER_MATCHES)->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    private static function escape(string $value): string
    {
        return str_replace(['%', '\\'], '', $value);
    }
}
