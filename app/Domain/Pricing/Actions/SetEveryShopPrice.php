<?php

namespace App\Domain\Pricing\Actions;

use App\Domain\Catalogue\Actions\SaveProduct;
use App\Domain\Catalogue\Data\SavedProduct;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Every shop" (module 4.3, SHOP-OR-EVERY-SHOP.md): moves the business price (`Product.sellPrice`, through
 * SaveProduct, so every till gets it) and ends the own prices of the shops chosen in `$endShopIds`, so those shops
 * sell at the new price too. Shops left out keep their own price: a shop price always beats the business price.
 *
 * @see EndShopPrice
 */
final class SetEveryShopPrice
{
    public function __construct(private readonly SaveProduct $save, private readonly EndShopPrice $end) {}

    /**
     * @param  list<string>  $endShopIds  shops whose own (base unit) price ends now
     */
    public function handle(Product $product, string $price, array $endShopIds = []): SavedProduct
    {
        $shops = Branch::query()->whereIn('id', $endShopIds)->get();

        if ($shops->count() !== count(array_unique($endShopIds))) {
            throw ValidationException::withMessages(['end_shop_ids' => 'Choose shops of this business.']);
        }

        return DB::transaction(function () use ($product, $price, $shops) {
            $saved = $this->save->handle($product, ['sell_price' => $price]);

            foreach ($shops as $shop) {
                $this->end->handle($shop, $product);
            }

            return $saved;
        });
    }
}
