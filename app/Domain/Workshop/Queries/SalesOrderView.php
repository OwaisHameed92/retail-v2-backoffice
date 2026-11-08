<?php

namespace App\Domain\Workshop\Queries;

use App\Domain\Shared\Support\ApiDate;
use App\Domain\Workshop\Enums\WorkshopOrderStatus;
use App\Domain\Workshop\Models\WorkshopOrder;
use App\Domain\Workshop\Models\WorkshopOrderLine;
use App\Domain\Workshop\Support\TillOrderState;

/**
 * Our `SalesOrder` answer (WORKSHOP-BIKEBOOK.md §3.6, §3.7): the till's pushed CustomerOrder decides the status once
 * it exists (`collected` → Complete, `cancelled` / `expired` → Cancelled, else Saved); before that the WorkshopOrder
 * (cancelled → Cancelled, "Complete" from BikeBook → Complete, else Saved). `paidTotal` is what the till took, else
 * what BikeBook said was paid in BikeBook. Money inc VAT, 2 dp.
 */
final class SalesOrderView
{
    /**
     * @return array<string, mixed>
     */
    public static function of(WorkshopOrder $order): array
    {
        $till = TillOrderState::of($order);
        $status = $till->bikeBookStatus() === 'Complete' || $till->bikeBookStatus() === 'Cancelled'
            ? $till->bikeBookStatus()
            : match (true) {
                $order->status === WorkshopOrderStatus::Cancelled => 'Cancelled',
                $order->completed_in_bikebook => 'Complete',
                default => 'Saved',
            };
        $paid = $till->customerOrderId !== null && ! $order->completed_in_bikebook ? $till->paidTotal : $order->paid_elsewhere;

        return [
            'id' => $order->id,
            'orderNumber' => $order->order_number,
            'reference' => $order->reference,
            'status' => $status,
            'store' => $order->branch_id,
            'customer' => $order->customer_id ?? ($order->external_customer_id !== '' ? $order->external_customer_id : null),
            'total' => (float) $order->goods_total,
            'paidTotal' => (float) $paid,
            'refundedTotal' => (float) $till->refundedTotal,
            'version' => $order->version,
            'lines' => $order->lines()->get()->map(fn (WorkshopOrderLine $line) => [
                'id' => $line->id,
                'externalId' => $line->external_line_id === '' ? null : $line->external_line_id,
                'variantId' => $line->product_id,
                'name' => $line->name,
                'quantity' => (float) $line->quantity,
                'paidPrice' => (float) $line->unit_price,
                'taxRate' => $line->vat_rate_id,
                'lineType' => $line->external_line_type !== '' ? $line->external_line_type : $line->line_type->bikeBook(),
            ])->values()->all(),
            'created' => ApiDate::format($order->created_at),
            'updated' => ApiDate::format($order->updated_at),
        ];
    }
}
