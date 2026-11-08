<?php

namespace App\Domain\Workshop\Support;

use App\Domain\Shared\Support\ApiDate;
use App\Domain\Sync\Enums\IdKind;
use App\Domain\Sync\Support\PullPayload;
use App\Domain\Workshop\Enums\WorkshopOrderStatus;
use App\Domain\Workshop\Models\WorkshopOrder;
use App\Domain\Workshop\Models\WorkshopOrderLine;

/**
 * The `WorkshopOrder` pull payload (contract proposal docs/contracts/proposals/workshop-order): every member of the
 * till's own entity schema `schemas/entities/WorkshopOrder.schema.json` (0.1.85), so the till applies it as its own
 * row once it moves the table from local to hub-owned. Portal-made values: `source` connector, `status` open or
 * cancelled, the till-side members (`customerOrderId`, `lockedBy…`, `saleId`, `collectedAt`, `isStarted`,
 * `priceDifference`) empty. `rowVersion` and `externalVersion` = our version (the till ignores a version it has).
 * Lines travel in `linesJson` as WORKSHOP-BIKEBOOK.md §5.1 lines. Company and shop ids are the till's own.
 */
final class WorkshopOrderPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function for(WorkshopOrder $order, PullPayload $ids): array
    {
        $open = $order->status === WorkshopOrderStatus::Open;

        return [
            'provider' => 'bikebook',
            'source' => 'connector',
            'externalOrderNumber' => $order->order_number,
            'externalJobNumber' => $order->external_job_number,
            'externalJobId' => $order->external_job_id,
            'externalInvoiceId' => $order->external_invoice_id,
            'reference' => $order->reference,
            'status' => $order->status->value,
            'jobStatus' => '',
            'isReady' => false,
            'customerId' => $order->customer_id,
            'externalCustomerId' => $order->external_customer_id,
            'customerName' => $order->customer_name,
            'customerPhone' => $order->customer_phone,
            'customerEmail' => $order->customer_email,
            'assetName' => $order->asset_name,
            'assetSerial' => $order->asset_serial,
            'externalAssetId' => $order->external_asset_id,
            'linesJson' => self::linesJson($order),
            'discountType' => $order->discount_type,
            'discountValue' => (float) $order->discount_value,
            'goodsTotal' => (float) $order->goods_total,
            'paidElsewhere' => (float) $order->paid_elsewhere,
            'billingType' => $order->billing_type,
            'isWarranty' => $order->billing_type === 'warranty',
            'isLeasing' => $order->billing_type === 'leasing',
            'isMotability' => $order->billing_type === 'motability',
            'dueAt' => ApiDate::format($order->due_at),
            'mechanicName' => '',
            'notes' => (string) $order->notes,
            'externalVersion' => $order->version,
            'receivedAt' => ApiDate::format($order->created_at),
            'priceDifference' => 0.0,
            'customerOrderId' => null,
            'lockedByRegisterId' => null,
            'lockedAt' => null,
            'saleId' => null,
            'collectedAt' => null,
            'cancelledAt' => ApiDate::format($order->cancelled_at),
            'isOpen' => $open,
            'isStarted' => false,
            'branchId' => $ids->till(IdKind::Branch, $order->branch_id),
            'id' => $order->id,
            'companyId' => $ids->till(IdKind::Company, $order->company_id),
            'createdAt' => ApiDate::format($order->created_at),
            'updatedAt' => ApiDate::format($order->updated_at),
            'rowVersion' => $order->version,
            'deletedAt' => null,
            'isDeleted' => false,
            'domainEvents' => [],
        ];
    }

    private static function linesJson(WorkshopOrder $order): string
    {
        $lines = $order->lines->map(fn (WorkshopOrderLine $line) => [
            'lineId' => $line->id,
            'externalLineId' => $line->external_line_id,
            'productId' => $line->product_id,
            'name' => $line->name,
            'qty' => (float) $line->quantity,
            'unitPrice' => (float) $line->unit_price,
            'vatRateId' => $line->vat_rate_id,
            'vatPercentage' => (float) $line->vat_percentage,
            'lineType' => $line->line_type->value,
            'lineTotal' => (float) $line->line_total,
            'vatAmount' => (float) $line->vat_amount,
        ])->values()->all();

        return (string) json_encode($lines, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
