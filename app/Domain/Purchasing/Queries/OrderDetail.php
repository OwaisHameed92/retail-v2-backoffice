<?php

namespace App\Domain\Purchasing\Queries;

use App\Domain\Purchasing\Support\HeadOfficeOrders;
use App\Domain\Purchasing\Support\PurchasingNames;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Models\GoodsReceipt;
use App\Domain\TillData\Models\PurchaseOrder;
use App\Domain\TillData\Models\PurchaseOrderLine;
use App\Domain\TillData\Models\SupplierInvoice;

/**
 * One purchase order (module 5.2): its lines with what has been received, the deliveries booked in against it and
 * their invoices. A head-office order the portal still owns can be edited, sent or cancelled; anything else is read
 * only, with the reason.
 */
final class OrderDetail
{
    /** @return array<string, mixed> */
    public static function for(PurchaseOrder $order): array
    {
        $lines = PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->orderBy('position')->get()->all();
        $deliveries = GoodsReceipt::query()->where('purchase_order_id', $order->id)->orderBy('received_date')->get();
        $invoices = SupplierInvoice::query()->whereIn('goods_receipt_id', $deliveries->pluck('id'))->orderBy('invoice_date')->get();
        $names = PurchasingNames::for([$order, ...$lines]);
        $received = Money::sum(array_map(fn (PurchaseOrderLine $l) => $l->received_qty ?? '0', $lines), 4);
        $ordered = Money::sum(array_map(fn (PurchaseOrderLine $l) => $l->ordered_units ?? '0', $lines), 4);
        $canManage = PurchasingPage::canManage();
        $portalOwns = HeadOfficeOrders::portalOwns($order);

        return [
            'order' => [
                'id' => $order->id,
                'reference' => HeadOfficeOrders::reference($order),
                'orderNo' => $order->order_no,
                'origin' => $order->origin->value ?? 'branch',
                'status' => $order->status?->value,
                'shop' => $names->shop($order->branch_id),
                'shopId' => $order->branch_id,
                'supplier' => $names->supplier($order->supplier_id),
                'supplierId' => $order->supplier_id,
                'expectedDate' => $order->expected_date?->format('Y-m-d'),
                'sentAt' => $order->sent_at?->toIso8601ZuluString(),
                'cancelledAt' => $order->cancelled_at?->toIso8601ZuluString(),
                'cancelReason' => $order->cancel_reason ?: null,
                'notes' => $order->notes ?: null,
                'createdAt' => $order->created_at?->toIso8601ZuluString(),
                'updatedAt' => $order->updated_at?->toIso8601ZuluString(),
                'draftedHere' => $order->getAttribute('hub_drafted_at') !== null,
                'withPortal' => $portalOwns,
            ],
            'totals' => [
                'net' => $order->net_total, 'vat' => $order->vat_total, 'gross' => $order->gross_total,
                'discount' => $order->discount_amount, 'ordered' => $ordered, 'received' => $received,
            ],
            'lines' => array_map(fn (PurchaseOrderLine $l) => [
                'id' => $l->id,
                'product' => $names->product($l->product_id),
                'cases' => $l->ordered_cases,
                'caseQty' => $l->case_qty_snapshot,
                'units' => $l->ordered_units,
                'received' => $l->received_qty,
                'unitCost' => $l->unit_cost_snapshot,
                'vatPercentage' => $l->vat_percentage,
                'net' => Money::mul($l->ordered_units ?? '0', $l->unit_cost_snapshot ?? '0'),
            ], $lines),
            'deliveries' => $deliveries->map(fn (GoodsReceipt $r) => [
                'id' => $r->id, 'reference' => $r->delivery_note_number ?: 'No delivery note', 'status' => $r->status?->value,
                'date' => $r->received_date->format('Y-m-d'), 'gross' => $r->gross_amount,
            ])->values()->all(),
            'invoices' => $invoices->map(fn (SupplierInvoice $i) => [
                'id' => $i->id, 'reference' => $i->invoice_number ?: 'No invoice number', 'status' => $i->status?->value,
                'date' => $i->invoice_date->format('Y-m-d'), 'gross' => $i->gross_amount,
            ])->values()->all(),
            'lockedReason' => HeadOfficeOrders::lockedReason($order),
            'can' => ['manage' => $canManage, 'edit' => $canManage && $portalOwns, 'send' => $canManage && $portalOwns && $order->status?->value === 'draft', 'cancel' => $canManage && $portalOwns],
        ];
    }
}
