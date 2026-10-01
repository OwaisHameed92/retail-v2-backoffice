<?php

namespace App\Domain\Purchasing\Reorder\Sources;

use App\Domain\Shared\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The per-product figures of one shop that a suggestion starts from (module 6.4), all read in bulk:
 *
 * - daily units sold (`rpt_product_daily`, qty − refunds, every till of the shop added up);
 * - open orders: ordered − received on the shop's orders that are draft, sent or part received (a head-office draft
 *   made from these suggestions counts, so running them again does not order twice);
 * - transfers on the way: dispatched to this shop and not yet received;
 * - shelf life: the median days from delivery to best-before of the product's latest batches at any shop
 *   (`StockLayer`, then delivery lines), only when under 60 days (longer never limits an order).
 */
final class ShopFigures
{
    public const SHORT_LIFE_MAX_DAYS = 60;

    /**
     * @param  list<string>  $productIds
     * @return array<string, array<string, string>> product id → "Y-m-d" → units
     */
    public static function dailySales(string $companyId, string $shopId, array $productIds, string $from, string $to): array
    {
        $out = [];

        foreach (array_chunk($productIds, 500) as $chunk) {
            $rows = DB::table('rpt_product_daily')->where('company_id', $companyId)->where('branch_id', $shopId)
                ->whereBetween('trading_day', [$from, $to])->whereIn('product_id', $chunk)
                ->get(['product_id', 'trading_day', 'qty', 'refund_qty']);

            foreach ($rows as $r) {
                $day = substr((string) $r->trading_day, 0, 10);
                $units = Money::sub($r->qty ?? 0, $r->refund_qty ?? 0, 4);
                $out[(string) $r->product_id][$day] = Money::add($out[(string) $r->product_id][$day] ?? '0', $units, 4);
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $productIds
     * @return array<string, string> product id → units still to arrive on open orders
     */
    public static function onOrder(string $companyId, string $shopId, array $productIds): array
    {
        $rows = DB::table('purchase_order_lines as l')
            ->join('purchase_orders as o', fn ($j) => $j->on('o.id', '=', 'l.purchase_order_id')->where('o.company_id', $companyId))
            ->where('l.company_id', $companyId)->where('o.branch_id', $shopId)->whereNull('o.deleted_at')->whereNull('l.deleted_at')
            ->whereIn('o.status', ['draft', 'sent', 'partReceived'])->whereIn('l.product_id', $productIds)
            ->get(['l.product_id', 'l.ordered_units', 'l.received_qty']);

        $out = [];

        foreach ($rows as $r) {
            $left = Money::sub($r->ordered_units ?? 0, $r->received_qty ?? 0, 4);

            if (Money::compare($left, '0') > 0) {
                $out[(string) $r->product_id] = Money::add($out[(string) $r->product_id] ?? '0', $left, 4);
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $productIds
     * @return array<string, string> product id → units dispatched to this shop and not yet received
     */
    public static function inTransit(string $companyId, string $shopId, array $productIds): array
    {
        $rows = DB::table('stock_transfer_lines as l')
            ->join('stock_transfers as t', fn ($j) => $j->on('t.id', '=', 'l.transfer_id')->where('t.company_id', $companyId))
            ->where('l.company_id', $companyId)->where('t.to_branch_id', $shopId)->where('t.status', 'dispatched')
            ->whereNull('t.deleted_at')->whereNull('l.deleted_at')->whereIn('l.product_id', $productIds)
            ->get(['l.product_id', 'l.qty_dispatched', 'l.qty_requested']);

        $out = [];

        foreach ($rows as $r) {
            $qty = Money::normalise($r->qty_dispatched ?? $r->qty_requested ?? 0, 4);
            $out[(string) $r->product_id] = Money::add($out[(string) $r->product_id] ?? '0', $qty, 4);
        }

        return $out;
    }

    /**
     * @param  list<string>  $productIds
     * @return array<string, int> product id → shelf life in days (short-life products only)
     */
    public static function shelfLives(string $companyId, array $productIds): array
    {
        $samples = [];
        $add = function (string $product, mixed $received, mixed $expiry) use (&$samples) {
            if ($received === null || $expiry === null || count($samples[$product] ?? []) >= 10) {
                return;
            }

            $days = (int) CarbonImmutable::parse(substr((string) $received, 0, 10))->diffInDays(CarbonImmutable::parse(substr((string) $expiry, 0, 10)), false);

            if ($days > 0 && $days <= 365) {
                $samples[$product][] = $days;
            }
        };

        foreach (array_chunk($productIds, 500) as $chunk) {
            DB::table('stock_layers')->where('company_id', $companyId)->whereNull('deleted_at')->whereIn('product_id', $chunk)
                ->whereNotNull('expiry_date')->whereNotNull('received_at')->orderByDesc('received_at')->limit(5000)
                ->get(['product_id', 'received_at', 'expiry_date'])
                ->each(fn (object $r) => $add((string) $r->product_id, $r->received_at, $r->expiry_date));

            DB::table('goods_receipt_lines as l')
                ->join('goods_receipts as g', fn ($j) => $j->on('g.id', '=', 'l.goods_receipt_id')->where('g.company_id', $companyId))
                ->where('l.company_id', $companyId)->whereNull('l.deleted_at')->whereIn('l.product_id', $chunk)
                ->whereNotNull('l.expiry_date')->whereNotNull('g.received_date')->orderByDesc('g.received_date')->limit(5000)
                ->get(['l.product_id', 'g.received_date', 'l.expiry_date'])
                ->each(fn (object $r) => $add((string) $r->product_id, $r->received_date, $r->expiry_date));
        }

        $out = [];

        foreach ($samples as $product => $days) {
            $median = LeadTimes::median($days);

            if ($median <= self::SHORT_LIFE_MAX_DAYS) {
                $out[$product] = $median;
            }
        }

        return $out;
    }
}
