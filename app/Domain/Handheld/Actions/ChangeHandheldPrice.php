<?php

namespace App\Domain\Handheld\Actions;

use App\Domain\Catalogue\Actions\SaveProduct;
use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Handheld\Support\PdaErrors;
use App\Domain\Handheld\Support\ShopProductFacts;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\Product;
use Illuminate\Validation\ValidationException;

/**
 * `PUT /api/pda/products/{id}/price`: the business price of a product (`Product.sellPrice`, base unit), as the legacy
 * endpoint changed it. Goes through SaveProduct, the portal product form's own path: a hub-owned save, so every till
 * pulls the new price at its next sync (HubOwnedRow stamps it), shelf labels are queued, and the `product.updated`
 * audit entry names the handheld user (the `handheld` guard). Shop prices are not touched: a shop with its own live
 * price keeps selling at it (the reply says so in `shop_retail` / `has_shop_price`).
 *
 * Only for a handheld user allowed to change prices (403 PRICE_CHANGE_NOT_ALLOWED); an archived product → 422
 * PRODUCT_ARCHIVED. The price format and limit are the product form's (TillProfile::priceRule, checked by the request).
 */
final class ChangeHandheldPrice
{
    public function __construct(private readonly SaveProduct $save) {}

    /**
     * @return array<string, mixed> the reply's `data`
     *
     * @throws ApiException|ValidationException
     */
    public function handle(HandheldUser $user, Branch $shop, Product $product, string $retail): array
    {
        if (! $user->can_change_prices) {
            throw PdaErrors::priceChangeNotAllowed();
        }

        if (! $product->is_active) {
            throw PdaErrors::productArchived();
        }

        $previous = Money::normalise($product->sell_price, 2);

        try {
            $saved = $this->save->handle($product, ['sell_price' => Money::normalise($retail, 2)]);
        } catch (ValidationException $e) {
            $errors = $e->errors();

            throw ValidationException::withMessages(isset($errors['sell_price'])
                ? ['retail' => $errors['sell_price']]
                : ['product' => 'Fix this product on the portal first: '.(collect($errors)->flatten()->first() ?? 'its details are not valid.')]);
        }

        $product = $saved->product->refresh();
        $facts = new ShopProductFacts($shop, [$product->id]);

        return [
            'id' => $product->id,
            'product_name' => $product->name,
            'barcode' => $facts->barcode($product),
            'previous_retail' => $previous,
            'retail' => Money::normalise($product->sell_price, 2),
            'shop_retail' => $facts->retail($product),
            'has_shop_price' => $facts->hasShopPrice($product),
            'changed' => $saved->changed !== [],
            'is_synced' => false,
        ];
    }
}
