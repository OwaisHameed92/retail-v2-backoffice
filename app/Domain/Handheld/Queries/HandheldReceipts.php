<?php

namespace App\Domain\Handheld\Queries;

use App\Domain\Handheld\Support\StockDraftView;
use App\Domain\Shared\Support\Money;
use App\Domain\StockDrafts\Models\StockDraft;
use App\Domain\StockDrafts\Queries\PurchaseOrderStock;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Enums\GoodsReceiptStatus;
use App\Domain\TillData\Models\GoodsReceipt;
use App\Domain\TillData\Models\GoodsReceiptLine;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\PurchaseOrder;
use App\Domain\TillData\Models\PurchaseOrderLine;

/**
 * `GET /api/pda/purchase-orders/{id}/receipts` and `…/returnable` (PDA_PHASE2_REQUEST §3): the deliveries of one
 * order of the shop — booked on the handheld (with their `portal_state`) and the till's own goods-in — newest first,
 * and per order line what was received, returned and can still be returned.
 */
final class HandheldReceipts
{
    public function __construct(private readonly HandheldStockDrafts $drafts) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function receipts(Branch $shop, PurchaseOrder $order): array
    {
        $drafted = array_map(fn (StockDraft $d) => StockDraftView::receipt($d), $this->drafts->receipts($shop, $order->id));
        $ids = array_column($drafted, 'id');
        $tills = GoodsReceipt::query()->where('branch_id', $shop->id)->where('purchase_order_id', $order->id)->whereNotIn('id', $ids)->get();
        $lines = $tills->isEmpty() ? collect() : GoodsReceiptLine::query()->whereIn('goods_receipt_id', $tills->pluck('id')->all())->get()->groupBy('goods_receipt_id');
        $names = Product::query()->withTrashed()->whereKey($lines->flatten(1)->pluck('product_id')->unique()->values()->all())->pluck('name', 'id');

        $own = $tills->map(fn (GoodsReceipt $r) => [
            'id' => $r->id,
            'reference_no' => $r->delivery_note_number,
            'received_at' => ($r->posted_at ?? $r->received_date)->toIso8601ZuluString(),
            'received_by' => null,
            'note' => $r->note,
            'client_reference' => null,
            'status' => $r->status?->value,
            'portal_state' => match ($r->status) {
                GoodsReceiptStatus::Posted => 'confirmed',
                GoodsReceiptStatus::Cancelled => 'cancelled',
                default => 'pending',
            },
            'editable' => false,
            'locked_reason' => 'Booked in on the shop’s till.',
            'reject_reason' => null,
            'confirmed_at' => $r->posted_at?->toIso8601ZuluString(),
            'items' => ($lines[$r->id] ?? collect())->map(fn (GoodsReceiptLine $l) => [
                'purchase_order_item_id' => $l->purchase_order_line_id,
                'product_id' => $l->product_id,
                'product_name' => $names[$l->product_id] ?? null,
                'quantity_received' => Money::normalise($l->received_qty, 4),
            ])->values()->all(),
        ])->values()->all();

        $all = [...$drafted, ...$own];
        usort($all, fn (array $a, array $b) => [$b['received_at'], $b['id']] <=> [$a['received_at'], $a['id']]);

        return $all;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function returnable(Branch $shop, PurchaseOrder $order): array
    {
        $this->drafts->refresh($shop);
        $lines = PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->orderBy('position')->get();
        $ids = $lines->pluck('id')->map(fn ($id) => (string) $id)->all();
        $pending = PurchaseOrderStock::pendingReceived($ids);
        $returned = PurchaseOrderStock::returned($ids);
        $names = Product::query()->withTrashed()->whereKey($lines->pluck('product_id')->unique()->values()->all())->pluck('name', 'id');

        return $lines->map(function (PurchaseOrderLine $line) use ($pending, $returned, $names): array {
            $received = Money::add($line->received_qty, $pending[$line->id] ?? 0, 4);
            $back = $returned[$line->id] ?? '0.0000';
            $left = Money::sub($received, $back, 4);

            return [
                'purchase_order_item_id' => $line->id,
                'product_id' => $line->product_id,
                'product_name' => $names[$line->product_id] ?? null,
                'unit_cost' => Money::normalise($line->unit_cost_snapshot, 4),
                'quantity_received' => $received,
                'quantity_returned' => $back,
                'quantity_returnable' => Money::isNegative($left) ? '0.0000' : $left,
            ];
        })->values()->all();
    }
}
