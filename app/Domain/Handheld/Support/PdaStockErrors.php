<?php

namespace App\Domain\Handheld\Support;

use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\StockDrafts\Exceptions\StockDraftLocked;

/**
 * Errors of the handheld stock calls (PDA_PHASE2_REQUEST §1, §1a, §3, §4; docs/pda-api.md "Stock documents"), in the
 * PDA envelope (PdaErrorRenderer). Validation-style refusals name the line in `errors` (top level), as a 422
 * VALIDATION_FAILED does.
 */
final class PdaStockErrors
{
    public const STOCK_CHANGE_NOT_ALLOWED = 'STOCK_CHANGE_NOT_ALLOWED';

    public const NOT_STOCK_TRACKED = 'NOT_STOCK_TRACKED';

    public const OVER_RECEIPT = 'OVER_RECEIPT';

    public const OVER_RETURN = 'OVER_RETURN';

    public const PO_NOT_RECEIVABLE = 'PO_NOT_RECEIVABLE';

    /** Per kind: [owned by the till, cancelled]. */
    private const LOCKED = [
        'adjustment' => ['ADJUSTMENT_OWNED_BY_TILL', 'ADJUSTMENT_CANCELLED'],
        'stockTake' => ['STOCK_TAKE_OWNED_BY_TILL', 'STOCK_TAKE_CANCELLED'],
        'purchaseReturn' => ['RETURN_OWNED_BY_TILL', 'RETURN_CANCELLED'],
        'goodsReceipt' => ['RECEIPT_OWNED_BY_TILL', 'RECEIPT_OWNED_BY_TILL'],
    ];

    public static function notAllowed(): ApiException
    {
        return new ApiException(self::STOCK_CHANGE_NOT_ALLOWED, 'This handheld account may not change stock. Ask your manager to allow it on the portal.', 403);
    }

    /** 409 …_OWNED_BY_TILL once the till has the document, …_CANCELLED for a cancelled one. */
    public static function locked(StockDraftLocked $e): ApiException
    {
        [$owned, $cancelled] = self::LOCKED[$e->draft->kind->value];

        return new ApiException($e->cancelled() ? $cancelled : $owned, $e->getMessage(), 409);
    }

    public static function notStockTracked(string $field, string $product): ApiException
    {
        return new ApiException(self::NOT_STOCK_TRACKED, "{$product} does not track stock, so its stock cannot be changed.", 422,
            details: ['errors' => [$field => ["{$product} does not track stock."]]]);
    }

    public static function archived(string $field, string $product): ApiException
    {
        return new ApiException(PdaErrors::PRODUCT_ARCHIVED, "{$product} is archived. Restore it on the portal before changing its stock.", 422,
            details: ['errors' => [$field => ["{$product} is archived."]]]);
    }

    /**
     * @param  array<string, list<string>>  $errors  field => messages
     */
    public static function overReceipt(array $errors): ApiException
    {
        return new ApiException(self::OVER_RECEIPT, 'More than is still to come on the order. '.(collect($errors)->flatten()->first() ?? ''), 422, details: ['errors' => $errors]);
    }

    /**
     * @param  array<string, list<string>>  $errors  field => messages
     */
    public static function overReturn(array $errors): ApiException
    {
        return new ApiException(self::OVER_RETURN, 'More than can be returned on the order. '.(collect($errors)->flatten()->first() ?? ''), 422, details: ['errors' => $errors]);
    }

    public static function notReceivable(): ApiException
    {
        return new ApiException(self::PO_NOT_RECEIVABLE, 'This order has not been placed yet (draft). Place it (status sent) before booking goods in.', 409);
    }
}
