<?php

namespace App\Domain\Handheld\Support;

use App\Domain\Handheld\Models\HandheldOrderReference;
use App\Domain\Purchasing\Support\HeadOfficeOrders;
use App\Domain\Shared\Support\Money;
use App\Domain\StockDrafts\Queries\PurchaseOrderStock;
use App\Domain\StockDrafts\Support\StockDraftSwitch;
use App\Domain\TillData\Enums\PurchaseOrderStatus;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductBarcode;
use App\Domain\TillData\Models\PurchaseOrder;
use App\Domain\TillData\Models\PurchaseOrderLine;
use App\Domain\TillData\Models\Supplier;
use Illuminate\Support\Collection;

/**
 * A purchase order in the handheld API (legacy shape where it fits). `portal_state`: `pending` while the portal still
 * owns a head-office order (editable here, waiting for the till), `confirmed` once the shop's till owns it (or for
 * the till's own orders), `cancelled`. Quantities are base units (`quantity_ordered` = cases × case size + loose).
 * `quantity_returned` / `total_returned` are null while PDA_STOCK_DRAFTS is off; on, they count returns, the lines'
 * `quantity_received` includes handheld deliveries the till has not booked yet (`quantity_pending`) and an order
 * with such a delivery is locked (contract proposal pda-stock-drafts §9). `client_reference` is the app's
 * idempotency UUID when the order was created from the handheld with one, else null.
 */
final class PurchaseOrderView
{
    /**
     * @param  Collection<int, PurchaseOrder>  $orders
     * @return list<array<string, mixed>>
     */
    public static function many(Collection $orders): array
    {
        $ids = $orders->pluck('id')->all();
        $lines = $ids === [] ? collect() : PurchaseOrderLine::query()->whereIn('purchase_order_id', $ids)->orderBy('position')->get()->groupBy('purchase_order_id');
        $productIds = $lines->flatten(1)->pluck('product_id')->unique()->values()->all();
        $products = $productIds === [] ? collect() : Product::query()->withTrashed()->whereKey($productIds)->pluck('name', 'id');
        $barcodes = $productIds === [] ? collect() : ProductBarcode::query()->whereIn('product_id', $productIds)->orderByDesc('is_primary')->orderBy('created_at')
            ->get(['product_id', 'barcode'])->unique('product_id')->pluck('barcode', 'product_id');
        $suppliers = Supplier::query()->withTrashed()->whereKey($orders->pluck('supplier_id')->unique()->values()->all())->pluck('name', 'id');
        $references = $ids === [] ? collect() : HandheldOrderReference::query()->whereIn('purchase_order_id', $ids)->pluck('client_reference', 'purchase_order_id');
        $stock = StockDraftSwitch::enabled() ? self::stock($ids, $lines->flatten(1)->pluck('id')->map(fn ($id) => (string) $id)->all()) : null;

        return $orders->map(fn (PurchaseOrder $order) => [
            ...self::one($order, $lines[$order->id] ?? collect(), $products, $barcodes, $suppliers, $stock),
            'client_reference' => $references[$order->id] ?? null,
        ])->values()->all();
    }

    /**
     * Handheld stock drafts (PDA_STOCK_DRAFTS on): deliveries waiting for the till, returns, orders being received.
     *
     * @param  list<string>  $orderIds
     * @param  list<string>  $lineIds
     * @return array{pending: array<string, string>, returned: array<string, string>, receiving: array<string, true>}
     */
    private static function stock(array $orderIds, array $lineIds): array
    {
        return ['pending' => PurchaseOrderStock::pendingReceived($lineIds), 'returned' => PurchaseOrderStock::returned($lineIds),
            'receiving' => PurchaseOrderStock::ordersReceiving($orderIds)];
    }

    /**
     * @param  Collection<int, PurchaseOrderLine>  $lines
     * @param  Collection<string, string>  $products
     * @param  Collection<string, string>  $barcodes
     * @param  Collection<string, string>  $suppliers
     * @param  array{pending: array<string, string>, returned: array<string, string>, receiving: array<string, true>}|null  $stock
     * @return array<string, mixed>
     */
    private static function one(PurchaseOrder $order, Collection $lines, Collection $products, Collection $barcodes, Collection $suppliers, ?array $stock = null): array
    {
        $items = $lines->map(function (PurchaseOrderLine $line) use ($products, $barcodes, $stock): array {
            $pending = $stock['pending'][$line->id] ?? '0.0000';
            $received = Money::add($line->received_qty, $pending, 4);

            return [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'product_name' => $products[$line->product_id] ?? null,
                'barcode' => $barcodes[$line->product_id] ?? null,
                'quantity_ordered' => Money::normalise($line->ordered_units, 4),
                'cases_ordered' => $line->ordered_cases,
                'case_qty' => $line->case_qty_snapshot,
                'quantity_received' => $received,
                'quantity_returned' => $stock === null ? null : ($stock['returned'][$line->id] ?? '0.0000'),
                'quantity_remaining' => self::remaining($line->ordered_units, $received),
                'unit_cost' => Money::normalise($line->unit_cost_snapshot, 4),
                'vat_percentage' => Money::normalise($line->vat_percentage, 4),
                'line_net' => Money::mul($line->ordered_units, $line->unit_cost_snapshot),
                ...($stock === null ? [] : ['quantity_pending' => $pending]),
            ];
        })->values();
        $locked = HeadOfficeOrders::lockedReason($order)
            ?? (isset($stock['receiving'][$order->id]) ? HandheldOrderGuard::RECEIVING : null);

        return [
            'id' => $order->id,
            'reference' => HeadOfficeOrders::reference($order),
            'order_no' => $order->order_no,
            'origin' => $order->origin?->value,
            'status' => $order->status?->value,
            'portal_state' => match (true) {
                $order->status === PurchaseOrderStatus::Cancelled => 'cancelled',
                $locked === null => 'pending',
                default => 'confirmed',
            },
            'editable' => $locked === null,
            'locked_reason' => $locked,
            'supplier_id' => $order->supplier_id,
            'supplier_name' => $suppliers[$order->supplier_id] ?? null,
            'expected_delivery_date' => $order->expected_date?->format('Y-m-d'),
            'notes' => $order->notes,
            'net_total' => Money::normalise($order->net_total, 2),
            'vat_total' => Money::normalise($order->vat_total, 2),
            'gross_total' => Money::normalise($order->gross_total, 2),
            'total_ordered' => Money::sum($items->pluck('quantity_ordered'), 4),
            'total_received' => Money::sum($items->pluck('quantity_received'), 4),
            'total_returned' => $stock === null ? null : Money::sum($items->pluck('quantity_returned'), 4),
            'total_remaining' => Money::sum($items->pluck('quantity_remaining'), 4),
            'sent_at' => $order->sent_at?->toIso8601ZuluString(),
            'cancelled_at' => $order->cancelled_at?->toIso8601ZuluString(),
            'cancel_reason' => $order->cancel_reason,
            'created_at' => $order->created_at?->toIso8601ZuluString(),
            'updated_at' => $order->updated_at?->toIso8601ZuluString(),
            'items' => $items->all(),
        ];
    }

    private static function remaining(mixed $ordered, mixed $received): string
    {
        $left = Money::sub($ordered, $received, 4);

        return Money::isNegative($left) ? '0.0000' : $left;
    }
}
