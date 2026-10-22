<?php

namespace App\Domain\StockDrafts\Enums;

/**
 * What a stock draft is (contract proposal pda-stock-drafts §3) and the till entities it goes down as: a stock
 * adjustment is a new `StockAdjustmentDraft` row (no lines); the other three reuse the till's own documents with
 * `origin: headOffice` and their lines.
 */
enum StockDraftKind: string
{
    case Adjustment = 'adjustment';
    case StockTake = 'stockTake';
    case GoodsReceipt = 'goodsReceipt';
    case PurchaseReturn = 'purchaseReturn';

    /** The pulled header entity. */
    public function entity(): string
    {
        return match ($this) {
            self::Adjustment => 'StockAdjustmentDraft',
            self::StockTake => 'StockTake',
            self::GoodsReceipt => 'GoodsReceipt',
            self::PurchaseReturn => 'PurchaseReturn',
        };
    }

    /** The pulled line entity (null: no lines). */
    public function lineEntity(): ?string
    {
        return match ($this) {
            self::Adjustment => null,
            self::StockTake => 'StockTakeLine',
            self::GoodsReceipt => 'GoodsReceiptLine',
            self::PurchaseReturn => 'PurchaseReturnLine',
        };
    }

    /** Per-shop number prefix (HS-LDS-000001 / HR-LDS-000001), or null when the document has no number. */
    public function numberPrefix(): ?string
    {
        return match ($this) {
            self::StockTake => 'HS',
            self::PurchaseReturn => 'HR',
            default => null,
        };
    }
}
