<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Enums\OrderLineOutcome;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineOrderLine;
use App\Domain\OnlineStore\Models\OnlineStoreProduct;
use App\Domain\Shared\Support\Money;

/**
 * OS-13: the shop's own stock for a business that sells without tills (OnlineStoreOnly): per product "In stock / Out of
 * stock" (`online_store_products.out_of_stock`) and an optional count (`stock_count`; null = not counted). Portal only:
 * never written to the till tables. Used by the catalogue (OnlineCatalogueQuery) only while the business has no live
 * till licence; once a till is live the tills' stock takes over and these values are ignored (kept, unused).
 *
 * Like the till's stock, a counted product loses what an online order took when the order is completed (collected or
 * delivered), and gets it back when that move is undone. Until then the open order holds it (OpenOnlineQuantities),
 * so the store never sells it twice. A cancelled or not-collected order never touches the count. Never below 0.
 */
final class ManualStock
{
    /** Tracked: marked out of stock, or counted. */
    public const TRACKED_SQL = 'CASE WHEN COALESCE(osp.out_of_stock, 0) = 1 OR osp.stock_count IS NOT NULL THEN 1 ELSE 0 END';

    /** On hand: 0 when marked out of stock, else the count (0 when not counted; not tracked then). */
    public const QTY_SQL = 'CASE WHEN COALESCE(osp.out_of_stock, 0) = 1 THEN 0 ELSE COALESCE(osp.stock_count, 0) END';

    /** Orderable now (the public lists' "in stock only"). */
    public const IN_STOCK_SQL = '(COALESCE(osp.out_of_stock, 0) = 0 AND (osp.stock_count IS NULL OR osp.stock_count > 0))';

    /** The catalogue of this business uses the shop's own stock (no live till; sells without tills). */
    public static function applies(string $companyId): bool
    {
        return OnlineStoreOnly::sellsWithoutTillsById($companyId);
    }

    /** The order was completed: its counted products lose what it took (lines marked unavailable take nothing). */
    public static function takeOff(OnlineOrder $order): void
    {
        self::adjust($order, false);
    }

    /** The completion was undone: the counted products get it back. */
    public static function putBack(OnlineOrder $order): void
    {
        self::adjust($order, true);
    }

    /** Statuses that take the stock off. */
    public static function completes(OnlineOrderStatus $status): bool
    {
        return in_array($status, [OnlineOrderStatus::Collected, OnlineOrderStatus::Delivered], true);
    }

    private static function adjust(OnlineOrder $order, bool $back): void
    {
        if (! self::applies($order->company_id)) {
            return;
        }

        $lines = OnlineOrderLine::withoutCompanyScope()->where('company_id', $order->company_id)->where('online_order_id', $order->id)->get();

        foreach ($lines as $line) {
            if ($line->outcome === OrderLineOutcome::Unavailable) {
                continue;
            }

            $row = OnlineStoreProduct::withoutCompanyScope()->where('company_id', $order->company_id)->where('product_id', $line->product_id)
                ->whereNotNull('stock_count')->lockForUpdate()->first();

            if ($row === null) {
                continue;
            }

            $count = $back ? Money::add((string) $row->stock_count, $line->quantity, 4) : Money::sub((string) $row->stock_count, $line->quantity, 4);
            $row->forceFill(['stock_count' => Money::compare($count, '0') < 0 ? '0.0000' : $count])->save();
        }
    }
}
