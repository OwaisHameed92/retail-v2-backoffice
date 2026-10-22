<?php

namespace App\Domain\Handheld\Support;

use App\Domain\Shared\Support\Money;
use App\Domain\StockDrafts\Enums\StockDraftKind;
use App\Domain\StockDrafts\Enums\StockDraftState;
use App\Domain\StockDrafts\Exceptions\StockDraftLocked;
use App\Domain\StockDrafts\Models\StockDraft;
use App\Domain\StockDrafts\Models\StockDraftLine;
use App\Domain\StockDrafts\Support\StockDraftPayloads;
use App\Domain\TillData\Models\Product;

/**
 * A stock draft in the handheld API (PDA_PHASE2_REQUEST §1, §1a, §3, §4). Every document has `status` (pending /
 * withTill / confirmed / rejected / cancelled), `portal_state` (pending / confirmed / rejected / cancelled),
 * `editable` / `locked_reason`, the till's `reject_reason` and `confirmed_at`, and the app's `client_reference`.
 * Quantities are base units (4 dp strings), money 2 dp.
 */
final class StockDraftView
{
    /**
     * @return array<string, mixed>
     */
    public static function of(StockDraft $draft): array
    {
        return match ($draft->kind) {
            StockDraftKind::Adjustment => self::adjustment($draft),
            StockDraftKind::StockTake => self::stockTake($draft),
            StockDraftKind::GoodsReceipt => self::receipt($draft),
            StockDraftKind::PurchaseReturn => self::purchaseReturn($draft),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function adjustment(StockDraft $draft): array
    {
        return [
            'id' => $draft->id,
            'client_reference' => $draft->client_reference,
            'product_id' => $draft->product_id,
            'product_name' => Product::query()->withTrashed()->whereKey($draft->product_id)->value('name'),
            'adjustment_type' => $draft->adjustment_type,
            'quantity' => self::qty($draft->quantity),
            'unit_cost' => $draft->unit_cost === null ? null : Money::normalise($draft->unit_cost, 4),
            'reason' => StockReasons::text($draft->reason, $draft->note, StockReasons::ADJUSTMENT),
            'reason_code' => $draft->reason,
            'product_unit_id' => $draft->product_unit_id,
            'entered_quantity' => $draft->entered_quantity === null ? null : self::qty($draft->entered_quantity),
            'conversion_to_base' => $draft->conversion_to_base === null ? null : self::qty($draft->conversion_to_base),
            'stock_on_hand_before' => $draft->stock_on_hand_before === null ? null : self::qty($draft->stock_on_hand_before),
            ...self::state($draft),
            'quantity_before' => $draft->qty_before === null ? null : self::qty($draft->qty_before),
            'quantity_change' => $draft->qty_delta === null ? null : self::qty($draft->qty_delta),
            'quantity_after' => $draft->qty_after === null ? null : self::qty($draft->qty_after),
            ...self::times($draft),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function stockTake(StockDraft $draft): array
    {
        return [
            'id' => $draft->id,
            'client_reference' => $draft->client_reference,
            'reference' => $draft->reference,
            'name' => $draft->name,
            'note' => $draft->note,
            'counted_at' => $draft->happened_at->toIso8601ZuluString(),
            ...self::state($draft),
            ...self::times($draft),
            'lines' => $draft->lines->map(fn (StockDraftLine $line) => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'product_name' => $line->product_name,
                'counted_quantity' => self::qty($line->quantity),
                'expected_quantity' => $line->expected_quantity === null ? null : self::qty($line->expected_quantity),
                'variance' => $line->variance === null ? null : self::qty($line->variance),
            ])->values()->all(),
        ];
    }

    /**
     * One delivery in `GET purchase-orders/{id}/receipts` (and the receive reply's `receipt`).
     *
     * @return array<string, mixed>
     */
    public static function receipt(StockDraft $draft): array
    {
        return [
            'id' => $draft->id,
            'reference_no' => $draft->reference_no,
            'received_at' => $draft->happened_at->toIso8601ZuluString(),
            'received_by' => $draft->entered_by,
            'note' => $draft->note,
            'client_reference' => $draft->client_reference,
            ...self::state($draft),
            'items' => $draft->lines->map(fn (StockDraftLine $line) => [
                'purchase_order_item_id' => $line->purchase_order_line_id,
                'product_id' => $line->product_id,
                'product_name' => $line->product_name,
                'quantity_received' => self::qty($line->quantity),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function purchaseReturn(StockDraft $draft): array
    {
        [$net, $vat] = StockDraftPayloads::totals($draft->lines);

        return [
            'id' => $draft->id,
            'client_reference' => $draft->client_reference,
            'reference' => $draft->reference,
            'supplier_id' => $draft->supplier_id,
            'supplier_name' => $draft->supplier_name,
            'purchase_order_id' => $draft->purchase_order_id,
            'reference_no' => $draft->reference_no,
            'return_reason' => $draft->note,
            ...self::state($draft),
            'net_total' => $net,
            'vat_total' => $vat,
            'gross_total' => Money::add($net, $vat, 2),
            ...self::times($draft),
            'items' => $draft->lines->map(fn (StockDraftLine $line) => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'product_name' => $line->product_name,
                'purchase_order_item_id' => $line->purchase_order_line_id,
                'quantity' => self::qty($line->quantity),
                'cost_price' => Money::normalise($line->unit_cost ?? 0, 4),
                'vat_percentage' => Money::normalise($line->vat_percentage ?? 0, 4),
                'reason' => StockReasons::text($line->reason, $line->note, StockReasons::RETURN),
                'reason_code' => $line->reason,
                'line_net' => StockDraftPayloads::net($line),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function state(StockDraft $draft): array
    {
        return [
            'status' => $draft->state->value,
            'portal_state' => $draft->state->portalState(),
            'editable' => $draft->state === StockDraftState::Pending,
            'locked_reason' => StockDraftLocked::reason($draft),
            'reject_reason' => $draft->state === StockDraftState::Rejected ? $draft->reject_reason : null,
            'confirmed_at' => $draft->state === StockDraftState::Confirmed ? $draft->outcome_at?->toIso8601ZuluString() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function times(StockDraft $draft): array
    {
        return [
            'cancelled_at' => $draft->cancelled_at?->toIso8601ZuluString(),
            'cancel_reason' => $draft->cancel_reason,
            'created_at' => $draft->created_at?->toIso8601ZuluString(),
            'updated_at' => $draft->updated_at?->toIso8601ZuluString(),
        ];
    }

    private static function qty(mixed $value): string
    {
        return Money::normalise($value ?? 0, 4);
    }
}
