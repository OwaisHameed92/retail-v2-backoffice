<?php

namespace App\Domain\Pricing\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Actions\SetBranchPrice;
use App\Domain\TillData\Models\BranchPrice;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductUnit;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * One shop's own price for a product (or one of its units) set on the portal (module 4.3). Always a **new**
 * BranchPrice row through SetBranchPrice (never an edit of a till's row); the pull sends it to that shop only, and
 * it beats the business price (`Product.sellPrice`) there from `validFrom` (now when empty) until `validTo`.
 *
 * Runs in the company scope: the caller passes a branch and product of the current business.
 */
final class SetShopPrice
{
    public function __construct(private readonly SetBranchPrice $set, private readonly RecordAudit $audit) {}

    public function handle(
        Branch $branch,
        Product $product,
        ?string $productUnitId,
        string $price,
        ?CarbonImmutable $validFrom = null,
        ?CarbonImmutable $validTo = null,
    ): BranchPrice {
        if (! $branch->is_active) {
            throw ValidationException::withMessages(['branch_id' => 'That shop is closed. Reopen it before giving it its own prices.']);
        }

        if ($product->company_id !== $branch->company_id) {
            throw ValidationException::withMessages(['branch_id' => 'That shop is not in this business.']);
        }

        if ($productUnitId !== null && ! ProductUnit::query()->where('product_id', $product->id)->whereKey($productUnitId)->exists()) {
            throw ValidationException::withMessages(['product_unit_id' => 'Choose one of this product\'s units.']);
        }

        if ($validTo !== null && $validTo->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            throw ValidationException::withMessages(['valid_to' => 'The end must be in the future.']);
        }

        $row = $this->set->handle($branch, $product->id, $productUnitId, $price, $validFrom, $validTo);

        $this->audit->handle('price.shop_set', $product, null, [
            'branch_id' => $branch->id, 'product_unit_id' => $productUnitId, 'price' => $row->price,
            'valid_from_utc' => $row->valid_from_utc->toIso8601ZuluString(), 'valid_to_utc' => $row->valid_to_utc?->toIso8601ZuluString(),
        ], ['name' => $product->name, 'shop' => $branch->name, 'branch_price_id' => $row->id]);

        return $row;
    }
}
