<?php

namespace App\Domain\Handheld\Actions;

use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Handheld\Support\HandheldStockGuard;
use App\Domain\Handheld\Support\StockReasons;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\Money;
use App\Domain\StockDrafts\Actions\SaveStockDraft;
use App\Domain\StockDrafts\Data\StockDraftData;
use App\Domain\StockDrafts\Enums\StockDraftKind;
use App\Domain\StockDrafts\Exceptions\StockDraftLocked;
use App\Domain\StockDrafts\Models\StockDraft;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\BranchProduct;
use App\Domain\TillData\Models\ProductUnit;
use Illuminate\Validation\ValidationException;

/**
 * `POST /api/pda/stock-adjustments` and `PUT …/{id}` (PDA_PHASE2_REQUEST §1): a `StockAdjustmentDraft` for the
 * user's shop (contract proposal pda-stock-drafts §5.1) that its till applies at its next sync.
 *
 * - "can adjust stock" only (403 STOCK_CHANGE_NOT_ALLOWED); a product of the business, not archived, tracking stock.
 * - `quantity` in base units, > 0 (≥ 0 for `stockCount`: the counted quantity). `unit_cost` for an increase or
 *   opening stock (else the product's cost); none for the others. `reason` required for a decrease: "Damaged",
 *   "Expired", "Theft", "Count correction", "Other", or any text (kept as the note, code `other`).
 * - `product_unit_id` / `entered_quantity` / `conversion_to_base`: display only; the unit must be the product's.
 * - `stock_on_hand_before`: what the till last reported for this shop.
 * - A repeated `client_reference` makes nothing and answers the first adjustment (200); edits only while the
 *   portal owns it (409 ADJUSTMENT_OWNED_BY_TILL / ADJUSTMENT_CANCELLED).
 */
final class SaveHandheldStockAdjustment
{
    public function __construct(private readonly SaveStockDraft $save) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: StockDraft, 1: bool} the adjustment and whether this call made it
     *
     * @throws ApiException|ValidationException
     */
    public function create(HandheldUser $user, Branch $shop, array $input, ?string $clientReference): array
    {
        HandheldStockGuard::assertMayAdjust($user);
        $existing = SaveStockDraft::existing($shop, StockDraftKind::Adjustment, $clientReference);

        return $existing !== null ? [$existing, false] : $this->save->create($shop, $this->data($user, $shop, $input, $clientReference));
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ApiException|ValidationException
     */
    public function update(HandheldUser $user, Branch $shop, StockDraft $draft, array $input): StockDraft
    {
        HandheldStockGuard::assertMayAdjust($user);
        HandheldStockGuard::unlocked(fn () => StockDraftLocked::unlessPending($draft));
        $data = $this->data($user, $shop, $input, null);

        return HandheldStockGuard::unlocked(fn () => $this->save->update($draft, $data));
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ApiException|ValidationException
     */
    private function data(HandheldUser $user, Branch $shop, array $input, ?string $clientReference): StockDraftData
    {
        $type = (string) $input['adjustment_type'];
        $quantity = Money::normalise($input['quantity'], 4);
        [$reason, $note] = StockReasons::parse(is_string($input['reason'] ?? null) ? $input['reason'] : null, StockReasons::ADJUSTMENT);

        if ($type !== 'stockCount' && Money::compare($quantity, '0') <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Enter a quantity above 0 (base units).']);
        }

        if ($type === 'stockDecrease' && $reason === null) {
            throw ValidationException::withMessages(['reason' => 'Give a reason for taking stock off (Damaged, Expired, Theft, Count correction or Other).']);
        }

        $product = HandheldStockGuard::product((string) $input['product_id'], 'product_id');
        $unitId = is_string($input['product_unit_id'] ?? null) && $input['product_unit_id'] !== '' ? $input['product_unit_id'] : null;

        if ($unitId !== null && ! ProductUnit::query()->where('product_id', $product->id)->whereKey($unitId)->exists()) {
            throw ValidationException::withMessages(['product_unit_id' => 'This is not a unit of the product.']);
        }

        $costed = in_array($type, ['stockIncrease', 'openingStock'], true);
        $stock = BranchProduct::query()->where('branch_id', $shop->id)->where('product_id', $product->id)->value('qty_on_hand');

        return new StockDraftData(StockDraftKind::Adjustment, [
            'product_id' => $product->id,
            'adjustment_type' => $type,
            'quantity' => $quantity,
            'unit_cost' => $costed ? Money::normalise($input['unit_cost'] ?? $product->cost_price ?? 0, 4) : null,
            'reason' => $reason,
            'note' => $note,
            'product_unit_id' => $unitId,
            'entered_quantity' => isset($input['entered_quantity']) ? Money::normalise($input['entered_quantity'], 4) : null,
            'conversion_to_base' => isset($input['conversion_to_base']) ? Money::normalise($input['conversion_to_base'], 4) : null,
            'stock_on_hand_before' => $stock === null ? null : Money::normalise($stock, 4),
            'happened_at' => now('UTC'),
        ], [], $clientReference, $user->id, HandheldStockGuard::enteredBy($user));
    }
}
