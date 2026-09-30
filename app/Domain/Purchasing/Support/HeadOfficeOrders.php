<?php

namespace App\Domain\Purchasing\Support;

use App\Domain\TillData\Enums\PurchaseOrderOrigin;
use App\Domain\TillData\Enums\PurchaseOrderStatus;
use App\Domain\TillData\Models\PurchaseOrder;

/**
 * Who owns a purchase order (DECISIONS "Head-office orders (§10.6)"). The portal may change a head-office order it
 * drafted until the shop's till pushes it (sent, cancelled or goods booked in: `origin_branch_id` set); a cancelled
 * order is never re-opened. Every other order is the shop's and read only here.
 */
final class HeadOfficeOrders
{
    public static function portalOwns(PurchaseOrder $order): bool
    {
        return $order->origin === PurchaseOrderOrigin::HeadOffice
            && $order->getAttribute('hub_drafted_at') !== null
            && $order->getAttribute('origin_branch_id') === null
            && $order->status !== PurchaseOrderStatus::Cancelled;
    }

    /** Why the portal may not change it, or null when it may. */
    public static function lockedReason(PurchaseOrder $order): ?string
    {
        return match (true) {
            $order->origin !== PurchaseOrderOrigin::HeadOffice || $order->getAttribute('hub_drafted_at') === null => 'This is the shop\'s own order. Only the shop can change it.',
            $order->getAttribute('origin_branch_id') !== null => 'The shop has sent, cancelled or started receiving this order, so it is the shop\'s now. Draft a new order instead.',
            $order->status === PurchaseOrderStatus::Cancelled => 'A cancelled order cannot be re-opened. Draft a new order instead.',
            default => null,
        };
    }

    /** `reference` (PO-LDS-000001) as the till wrote it; an order without a shop code shows `orderNo` (§10.6). */
    public static function reference(PurchaseOrder $order): string
    {
        return $order->reference ?: ($order->order_no ?: sprintf('PO-%06d', $order->number));
    }
}
