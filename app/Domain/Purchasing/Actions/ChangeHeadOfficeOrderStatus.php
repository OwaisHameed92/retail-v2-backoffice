<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Actions\DraftHeadOfficeOrder;
use App\Domain\TillData\Data\HeadOfficeOrderData;
use App\Domain\TillData\Data\HeadOfficeOrderLine;
use App\Domain\TillData\Enums\PurchaseOrderStatus;
use App\Domain\TillData\Models\PurchaseOrder;
use App\Domain\TillData\Models\PurchaseOrderLine;
use Illuminate\Validation\ValidationException;

/**
 * Sends a drafted head-office order (placed with the supplier: the shop can receive it) or cancels it (withdrawn
 * before any goods arrive), from the order page (module 5.2). The order is sent down whole again with its stored
 * lines; DraftHeadOfficeOrder refuses it once the shop owns the order or when it is already cancelled.
 *
 *     app(ChangeHeadOfficeOrderStatus::class)->handle($order, PurchaseOrderStatus::Cancelled, 'Supplier out of stock');
 */
final class ChangeHeadOfficeOrderStatus
{
    public function __construct(private readonly DraftHeadOfficeOrder $draft) {}

    /**
     * @throws ValidationException
     */
    public function handle(PurchaseOrder $order, PurchaseOrderStatus $status, ?string $reason = null): PurchaseOrder
    {
        if (! in_array($status, [PurchaseOrderStatus::Sent, PurchaseOrderStatus::Cancelled], true)) {
            throw ValidationException::withMessages(['status' => 'An order can only be sent or cancelled here.']);
        }

        if ($status === PurchaseOrderStatus::Sent && $order->status !== PurchaseOrderStatus::Draft) {
            throw ValidationException::withMessages(['order' => 'Only a draft order can be sent.']);
        }

        $shop = Branch::query()->withTrashed()->findOrFail($order->branch_id);
        $lines = PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->orderBy('position')->get()
            ->map(fn (PurchaseOrderLine $l) => new HeadOfficeOrderLine(
                (string) $l->product_id, (int) $l->ordered_cases, max(1, (int) $l->case_qty_snapshot), (string) $l->unit_cost_snapshot,
                (string) $l->vat_rate_id, (string) $l->vat_percentage,
                max(0, (int) bcsub($l->ordered_units ?? '0', (string) ($l->ordered_cases * $l->case_qty_snapshot), 0)),
            ))->values()->all();

        return $this->draft->handle($shop, new HeadOfficeOrderData(
            (string) $order->supplier_id, $status, $lines, $order->expected_date?->format('Y-m-d'), $order->notes,
            $status === PurchaseOrderStatus::Cancelled ? (trim((string) $reason) ?: null) : null,
        ), $order->id);
    }
}
