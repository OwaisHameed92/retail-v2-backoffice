<?php

namespace App\Domain\Handheld\Support;

use App\Domain\Purchasing\Support\HeadOfficeOrders;
use App\Domain\Shared\Support\Money;
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
 * `quantity_returned` is null until purchase returns come to the app (phase 2).
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

        return $orders->map(fn (PurchaseOrder $order) => self::one($order, $lines[$order->id] ?? collect(), $products, $barcodes, $suppliers))->values()->all();
    }

    /**
     * @param  Collection<int, PurchaseOrderLine>  $lines
     * @param  Collection<string, string>  $products
     * @param  Collection<string, string>  $barcodes
     * @param  Collection<string, string>  $suppliers
     * @return array<string, mixed>
     */
    private static function one(PurchaseOrder $order, Collection $lines, Collection $products, Collection $barcodes, Collection $suppliers): array
    {
        $items = $lines->map(fn (PurchaseOrderLine $line) => [
            'id' => $line->id,
            'product_id' => $line->product_id,
            'product_name' => $products[$line->product_id] ?? null,
            'barcode' => $barcodes[$line->product_id] ?? null,
            'quantity_ordered' => Money::normalise($line->ordered_units, 4),
            'cases_ordered' => $line->ordered_cases,
            'case_qty' => $line->case_qty_snapshot,
            'quantity_received' => Money::normalise($line->received_qty, 4),
            'quantity_returned' => null,
            'quantity_remaining' => self::remaining($line->ordered_units, $line->received_qty),
            'unit_cost' => Money::normalise($line->unit_cost_snapshot, 4),
            'vat_percentage' => Money::normalise($line->vat_percentage, 4),
            'line_net' => Money::mul($line->ordered_units, $line->unit_cost_snapshot),
        ])->values();
        $locked = HeadOfficeOrders::lockedReason($order);

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
            'total_returned' => null,
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
