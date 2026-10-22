<?php

namespace App\Domain\Handheld\Support;

use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\StockDrafts\Exceptions\StockDraftLocked;
use App\Domain\TillData\Models\Product;
use Closure;
use Illuminate\Validation\ValidationException;

/**
 * Checks every handheld stock write shares (PDA_PHASE2_REQUEST §1): the user's "can adjust stock" permission (403
 * STOCK_CHANGE_NOT_ALLOWED), a product of the business (422 naming the field), not archived (422 PRODUCT_ARCHIVED),
 * tracking stock (422 NOT_STOCK_TRACKED); and a draft the till has taken on → 409 (…_OWNED_BY_TILL / …_CANCELLED).
 * The till checks the same again when it applies the draft (contract proposal pda-stock-drafts §8).
 */
final class HandheldStockGuard
{
    /**
     * @throws ApiException
     */
    public static function assertMayAdjust(HandheldUser $user): void
    {
        if (! $user->can_adjust_stock) {
            throw PdaStockErrors::notAllowed();
        }
    }

    /**
     * A product of the business whose stock may change (company scope: another business's id is "not found").
     *
     * @throws ApiException|ValidationException
     */
    public static function product(string $id, string $field): Product
    {
        $product = Product::query()->find($id);

        if (! $product instanceof Product) {
            throw ValidationException::withMessages([$field => 'Choose one of this business\'s products.']);
        }

        if (! $product->is_active || $product->archived_at !== null) {
            throw PdaStockErrors::archived($field, $product->name);
        }

        if (! $product->track_stock) {
            throw PdaStockErrors::notStockTracked($field, $product->name);
        }

        return $product;
    }

    /** What the till shows as "entered by" (our users are not till users). */
    public static function enteredBy(HandheldUser $user): string
    {
        return mb_substr($user->name.' (handheld)', 0, 120);
    }

    /**
     * Runs a change of a draft, turning "the till has it" into the handheld's 409.
     *
     * @template T
     *
     * @param  Closure(): T  $change
     * @return T
     *
     * @throws ApiException
     */
    public static function unlocked(Closure $change): mixed
    {
        try {
            return $change();
        } catch (StockDraftLocked $e) {
            throw PdaStockErrors::locked($e);
        }
    }
}
