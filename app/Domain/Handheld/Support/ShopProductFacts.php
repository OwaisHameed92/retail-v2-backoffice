<?php

namespace App\Domain\Handheld\Support;

use App\Domain\Pricing\Support\ShopPrices;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\BranchPrice;
use App\Domain\TillData\Models\BranchProduct;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductBarcode;

/**
 * What one shop knows about a page of products, for the handheld lists: the price it sells at (its own live shop
 * price, else the business price — as the till reads it, ShopPrices), stock on hand as its till last reported it
 * (`branch_products`, null when the till has not), and the primary barcode.
 */
final class ShopProductFacts
{
    /** @var array<string, array<string, array<string, BranchPrice>>> */
    private array $live;

    /** @var array<string, string> */
    private array $stock;

    /** @var array<string, string> */
    private array $barcodes;

    /**
     * @param  list<string>  $productIds
     */
    public function __construct(private readonly Branch $shop, array $productIds)
    {
        $this->live = $productIds === [] ? [] : ShopPrices::live($productIds, [$shop->id]);
        $this->stock = $productIds === [] ? [] : BranchProduct::query()->where('branch_id', $shop->id)->whereIn('product_id', $productIds)
            ->pluck('qty_on_hand', 'product_id')->map(fn (mixed $qty) => Money::normalise($qty ?? 0, 4))->all();
        $this->barcodes = [];

        $rows = $productIds === [] ? collect() : ProductBarcode::query()->whereIn('product_id', $productIds)
            ->orderByDesc('is_primary')->orderBy('created_at')->get(['product_id', 'barcode']);

        foreach ($rows as $row) {
            $this->barcodes[$row->product_id] ??= $row->barcode;
        }
    }

    /** The price this shop sells the product's base unit at. */
    public function retail(Product $product): string
    {
        $own = $this->live[$product->id][$this->shop->id][''] ?? null;

        return Money::normalise($own !== null ? $own->price : $product->sell_price, 2);
    }

    public function hasShopPrice(Product $product, ?string $productUnitId = null): bool
    {
        return isset($this->live[$product->id][$this->shop->id][$productUnitId ?? '']);
    }

    /** A unit's price in this shop: its own live shop price for that unit, else the unit's business price. */
    public function unitRetail(Product $product, string $productUnitId, string $businessPrice): string
    {
        $own = $this->live[$product->id][$this->shop->id][$productUnitId] ?? null;

        return Money::normalise($own !== null ? $own->price : $businessPrice, 2);
    }

    public function stock(Product $product): ?string
    {
        return $this->stock[$product->id] ?? null;
    }

    public function barcode(Product $product): ?string
    {
        return $this->barcodes[$product->id] ?? null;
    }

    /**
     * One product in the handheld lists (legacy `products` shape; money and quantities as decimal strings).
     *
     * @return array<string, mixed>
     */
    public function row(Product $product): array
    {
        return [
            'id' => $product->id,
            'product_name' => $product->name,
            'barcode' => $this->barcode($product),
            'product_code' => $product->sku,
            'cost' => Money::normalise($product->cost_price, 4),
            'retail' => $this->retail($product),
            'business_retail' => Money::normalise($product->sell_price, 2),
            'has_shop_price' => $this->hasShopPrice($product),
            'unit_type' => $product->unit_type?->value,
            'unit_code' => $product->unit_code,
            'vat_rate_id' => $product->vat_rate_id,
            'stock_on_hand' => $this->stock($product),
            'is_active' => $product->is_active,
        ];
    }
}
