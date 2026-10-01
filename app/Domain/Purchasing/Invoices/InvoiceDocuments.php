<?php

namespace App\Domain\Purchasing\Invoices;

use App\Domain\Purchasing\Support\HeadOfficeOrders;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Enums\GoodsReceiptStatus;
use App\Domain\TillData\Enums\PurchaseOrderStatus;
use App\Domain\TillData\Models\GoodsReceipt;
use App\Domain\TillData\Models\GoodsReceiptLine;
use App\Domain\TillData\Models\PurchaseOrder;
use App\Domain\TillData\Models\PurchaseOrderLine;
use Illuminate\Database\Eloquent\Collection;

/**
 * The shop's own order and delivery an invoice belongs to (module 6.5), read only (both are till-owned):
 *
 * - Order: an open order (sent or part received) of that shop from that supplier, by the order reference printed on
 *   the invoice, else the one with the most of the invoice's products.
 * - Delivery: a booked-in delivery of that shop from that supplier whose delivery note number is the invoice's number
 *   or order reference, else one booked in against the matched order.
 *
 * `reference()` gives the units and cost per item each product was ordered or received at, for the line checks.
 */
final class InvoiceDocuments
{
    public const OPEN = [PurchaseOrderStatus::Sent, PurchaseOrderStatus::PartReceived];

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public static function apply(array $draft, string $shopId): array
    {
        if ($draft['documentPinned'] ?? false) {
            return $draft;
        }

        $supplierId = $draft['supplierId'] ?? null;
        $draft['purchaseOrderId'] = null;
        $draft['goodsReceiptId'] = null;

        if ($supplierId === null) {
            return $draft;
        }

        $orders = self::openOrders($shopId, $supplierId);
        $wanted = array_filter([$draft['orderReference'] ?? null, $draft['invoiceNumber'] ?? null]);
        $order = $orders->first(fn (PurchaseOrder $o) => array_intersect(array_map('mb_strtolower', $wanted), array_map('mb_strtolower', array_filter([
            $o->reference, $o->order_no, HeadOfficeOrders::reference($o),
        ]))) !== []);

        if ($order === null) {
            $products = array_filter(array_column($draft['lines'], 'productId'));
            $best = 0;

            foreach ($orders as $candidate) {
                $overlap = PurchaseOrderLine::query()->where('purchase_order_id', $candidate->id)->whereIn('product_id', $products)->count();

                if ($overlap > $best) {
                    [$order, $best] = [$candidate, $overlap];
                }
            }
        }

        $deliveries = GoodsReceipt::query()->where('branch_id', $shopId)->where('supplier_id', $supplierId)
            ->where('status', '!=', GoodsReceiptStatus::Cancelled->value)->orderByDesc('received_date')->limit(50)->get();
        $delivery = $deliveries->first(fn (GoodsReceipt $g) => in_array(mb_strtolower((string) $g->delivery_note_number), array_map('mb_strtolower', $wanted), true))
            ?? ($order !== null ? $deliveries->firstWhere('purchase_order_id', $order->id) : null);

        $draft['purchaseOrderId'] = $order->id ?? $delivery?->purchase_order_id;
        $draft['goodsReceiptId'] = $delivery?->id;

        return $draft;
    }

    /** @return Collection<int, PurchaseOrder> */
    public static function openOrders(string $shopId, ?string $supplierId): Collection
    {
        return PurchaseOrder::query()->where('branch_id', $shopId)
            ->when($supplierId !== null, fn ($q) => $q->where('supplier_id', $supplierId))
            ->whereIn('status', array_map(fn (PurchaseOrderStatus $s) => $s->value, self::OPEN))
            ->orderByDesc('created_at')->limit(50)->get();
    }

    /**
     * Units and cost per item of each product on the linked delivery (preferred: what arrived) or order.
     *
     * @param  array<string, mixed>  $draft
     * @return array{source: 'delivery'|'order'|null, lines: array<string, array{units: string, unitCost: string}>}
     */
    public static function reference(array $draft): array
    {
        $lines = [];

        if (($draft['goodsReceiptId'] ?? null) !== null) {
            foreach (GoodsReceiptLine::query()->where('goods_receipt_id', $draft['goodsReceiptId'])->get() as $line) {
                $lines[$line->product_id] = self::add($lines[$line->product_id] ?? null, (string) $line->received_qty, (string) $line->unit_cost);
            }

            return ['source' => 'delivery', 'lines' => $lines];
        }

        if (($draft['purchaseOrderId'] ?? null) !== null) {
            foreach (PurchaseOrderLine::query()->where('purchase_order_id', $draft['purchaseOrderId'])->get() as $line) {
                $lines[$line->product_id] = self::add($lines[$line->product_id] ?? null, (string) $line->ordered_units, (string) $line->unit_cost_snapshot);
            }

            return ['source' => 'order', 'lines' => $lines];
        }

        return ['source' => null, 'lines' => []];
    }

    /**
     * @param  array{units: string, unitCost: string}|null  $current
     * @return array{units: string, unitCost: string}
     */
    private static function add(?array $current, string $units, string $cost): array
    {
        return ['units' => Money::add($current['units'] ?? '0', $units, 4), 'unitCost' => $current['unitCost'] ?? Money::normalise($cost, 4)];
    }
}
