<?php

namespace App\Domain\Handheld\Actions;

use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Handheld\Support\PdaErrors;
use App\Domain\Handheld\Support\ShopProductFacts;
use App\Domain\Pricing\Actions\EndShopPrice;
use App\Domain\Pricing\Actions\SetShopPrice;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductUnit;
use Illuminate\Validation\ValidationException;

/**
 * `PUT /api/pda/products/{id}/shop-price {retail|null}` (and the `scope: shop` unit price): this shop's own price for
 * the product's base unit (or one unit), through the portal shop price screen's own actions (module 4.3):
 * SetShopPrice writes a **new** BranchPrice row (never an edit of a till's row) that the pull sends to this shop only;
 * `null` ends the shop's own live price(s) now (EndShopPrice, rows set by the till included), so the shop sells at
 * the business price again. Audited as `price.shop_set` / `price.shop_ended` naming the handheld user; shelf labels
 * are queued as on the portal.
 *
 * The same live own price again writes nothing (`changed: false`), so a retried call does not add rows. Only for a
 * user with `can_change_prices` (403 PRICE_CHANGE_NOT_ALLOWED); archived product → 422 PRODUCT_ARCHIVED.
 */
final class SetHandheldShopPrice
{
    public function __construct(private readonly SetShopPrice $set, private readonly EndShopPrice $end) {}

    /**
     * @return array<string, mixed> the reply's `data`
     *
     * @throws ApiException|ValidationException
     */
    public function handle(HandheldUser $user, Branch $shop, Product $product, ?string $retail): array
    {
        $change = $this->change($user, $shop, $product, null, $retail);

        return [
            'id' => $product->id,
            'product_name' => $product->name,
            'previous_shop_retail' => $change['previous'],
            'shop_retail' => $change['current'],
            'business_retail' => $change['business'],
            'has_shop_price' => $change['has_shop_price'],
            'changed' => $change['changed'],
            'is_synced' => false,
        ];
    }

    /**
     * Sets (or with null, ends) this shop's own price of the base unit (`$unit` null) or a unit. `previous` / `current`
     * are what the shop charges before / after (its own live price, else the business price).
     *
     * @return array{previous: string, current: string, business: string, had_shop_price: bool, has_shop_price: bool, changed: bool}
     *
     * @throws ApiException|ValidationException
     */
    public function change(HandheldUser $user, Branch $shop, Product $product, ?ProductUnit $unit, ?string $retail, string $field = 'retail'): array
    {
        if (! $user->can_change_prices) {
            throw PdaErrors::priceChangeNotAllowed();
        }

        if (! $product->is_active) {
            throw PdaErrors::productArchived();
        }

        $facts = new ShopProductFacts($shop, [$product->id]);
        $business = Money::normalise($unit !== null ? $unit->sell_price_inc_vat : $product->sell_price, 2);
        $own = $facts->shopPrice($product, $unit?->id);
        $previous = $own !== null ? Money::normalise($own->price, 2) : $business;
        $result = ['previous' => $previous, 'current' => $previous, 'business' => $business, 'had_shop_price' => $own !== null,
            'has_shop_price' => $own !== null, 'changed' => false];

        if ($retail === null) {
            $ended = $this->end->handle($shop, $product, $unit?->id);

            return [...$result, 'current' => $business, 'has_shop_price' => false, 'changed' => $ended > 0];
        }

        $retail = Money::normalise($retail, 2);

        if ($own !== null && $own->valid_to_utc === null && Money::compare($own->price, $retail) === 0) {
            return $result; // Already this shop's own price, open-ended: nothing to write.
        }

        try {
            $row = $this->set->handle($shop, $product, $unit?->id, $retail);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages([$field => collect($e->errors())->flatten()->first() ?? 'This price cannot be saved.']);
        }

        return [...$result, 'current' => Money::normalise($row->price, 2), 'has_shop_price' => true, 'changed' => true];
    }
}
