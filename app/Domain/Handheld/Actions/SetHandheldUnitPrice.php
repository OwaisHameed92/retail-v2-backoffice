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
use App\Domain\TillData\Models\ProductUnit;
use App\Domain\TillData\Models\Unit;
use Illuminate\Validation\ValidationException;

/**
 * `PUT /api/pda/products/{id}/units/{product_unit_id}/price {retail_price, scope}`: the price of one of a product's
 * extra units (CASE, BOX…; the base unit stays on the product calls).
 *
 * - `scope: business` (default): the unit's own price (`ProductUnit.sellPriceIncVat`) through SaveProduct with the
 *   product's units as stored and only this unit's price changed, which is what the product form sends: hub-owned, so
 *   every till pulls it, audited `product.updated` (`units`) naming the handheld user.
 * - `scope: shop`: this shop's own price for that unit (a BranchPrice with `productUnitId`, the portal shop price
 *   screen's path, SetHandheldShopPrice), pulled by this shop only; `retail_price: null` ends it.
 *
 * Only for a user with `can_change_prices` (403 PRICE_CHANGE_NOT_ALLOWED); archived → 422 PRODUCT_ARCHIVED.
 */
final class SetHandheldUnitPrice
{
    public function __construct(private readonly SaveProduct $save, private readonly SetHandheldShopPrice $shopPrice) {}

    /**
     * @return array<string, mixed> the reply's `data`
     *
     * @throws ApiException|ValidationException
     */
    public function handle(HandheldUser $user, Branch $shop, Product $product, ProductUnit $unit, ?string $retail, string $scope): array
    {
        if ($scope === 'shop') {
            return $this->reply($product, $unit, $scope, $this->shopPrice->change($user, $shop, $product, $unit, $retail, 'retail_price'));
        }

        if (! $user->can_change_prices) {
            throw PdaErrors::priceChangeNotAllowed();
        }

        if (! $product->is_active) {
            throw PdaErrors::productArchived();
        }

        $own = (new ShopProductFacts($shop, [$product->id]))->shopPrice($product, $unit->id);
        $previous = Money::normalise($own !== null ? $own->price : $unit->sell_price_inc_vat, 2);
        $retail = Money::normalise((string) $retail, 2);

        $rows = ProductUnit::query()->where('product_id', $product->id)->orderBy('position')->orderBy('id')->get()
            ->map(fn (ProductUnit $row) => [
                'id' => $row->id, 'unit_id' => (string) $row->unit_id, 'conversion_factor' => (string) $row->conversion_factor,
                'sell_price_inc_vat' => $row->id === $unit->id ? $retail : (string) $row->sell_price_inc_vat, 'cost' => (string) $row->cost,
                'is_default_sell_unit' => (bool) $row->is_default_sell_unit, 'is_purchase_unit' => (bool) $row->is_purchase_unit,
                'is_default_purchase_unit' => (bool) $row->is_default_purchase_unit,
            ])->values()->all();

        try {
            $saved = $this->save->handle($product, [], null, $rows);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['product' => 'Fix this product on the portal first: '.(collect($e->errors())->flatten()->first() ?? 'its details are not valid.')]);
        }

        $business = Money::normalise($unit->refresh()->sell_price_inc_vat, 2);

        return $this->reply($product, $unit, $scope, [
            'previous' => $previous, 'current' => $own !== null ? Money::normalise($own->price, 2) : $business, 'business' => $business,
            'had_shop_price' => $own !== null, 'has_shop_price' => $own !== null, 'changed' => in_array('units', $saved->changed, true),
        ]);
    }

    /**
     * @param  array{previous: string, current: string, business: string, had_shop_price: bool, has_shop_price: bool, changed: bool}  $change
     * @return array<string, mixed>
     */
    private function reply(Product $product, ProductUnit $unit, string $scope, array $change): array
    {
        $type = Unit::query()->find($unit->unit_id);

        return [
            'id' => $product->id,
            'product_name' => $product->name,
            'product_unit_id' => $unit->id,
            'unit_code' => $type?->code,
            'unit_name' => $type?->name,
            'conversion_to_base' => Money::normalise($unit->conversion_factor, 4),
            'scope' => $scope,
            'previous_retail_price' => $change['previous'],
            'retail_price' => $change['current'],
            'business_retail_price' => $change['business'],
            'has_shop_price' => $change['has_shop_price'],
            'changed' => $change['changed'],
            'is_synced' => false,
        ];
    }
}
