<?php

namespace App\Domain\Workshop\Queries;

use App\Domain\Pricing\Support\ShopPrices;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Models\BranchPrice;
use App\Domain\TillData\Models\Product;
use App\Domain\Workshop\Models\WorkshopConnection;
use App\Domain\Workshop\Support\ProductScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * BikeBook's `Inventory` (WORKSHOP-BIKEBOOK.md §3.4) for the connected shop: `{variant, store, quantity, price}`.
 *
 * - `quantity` = on hand − reserved as the till last pushed it (BranchProduct), never below 0; `null` for a product
 *   the shop does not track (open question Q3 to BikeBook); 0 when the till never reported the product.
 * - `price` = the shop's shelf price inc VAT, the till's rule: a live BranchPrice for the shop (base unit), else
 *   BranchProduct.priceOverride, else Product.sellPrice; 2 dp.
 * - Variants only (a single product or a variant child; never a variant parent), active ones.
 */
final class ConnectorInventory
{
    /**
     * @param  list<string>  $variantIds
     * @return list<array<string, mixed>>
     */
    public function forVariants(WorkshopConnection $connection, array $variantIds): array
    {
        $variantIds = array_values(array_unique(array_filter($variantIds, fn (string $id) => $id !== '')));

        if ($variantIds === []) {
            return [];
        }

        $products = Product::query()->whereIn('id', $variantIds)->where('is_variant_parent', false)
            ->where('is_active', true)->whereNull('archived_at')->get()->keyBy('id');

        return $this->rows($connection, array_values(array_filter($variantIds, fn (string $id) => $products->has($id))), $products->all());
    }

    /**
     * Every in-scope variant whose stock or price for this shop (or its product row) changed at or after `$since`.
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function changedSince(WorkshopConnection $connection, CarbonImmutable $since, int $page, int $size): array
    {
        $at = $since->utc()->format('Y-m-d H:i:s');
        $since = fn (Builder $q) => $q->where('updated_at', '>=', $at)->orWhere('synced_at', '>=', $at);
        $touched = DB::table('branch_products')->select('product_id')->where('company_id', $connection->company_id)->where('branch_id', $connection->branch_id)->where($since)
            ->union(DB::table('branch_prices')->select('product_id')->where('company_id', $connection->company_id)->where('branch_id', $connection->branch_id)->where($since))
            ->union(DB::table('products')->select('id')->where('company_id', $connection->company_id)->where($since));
        $query = $this->variantsInScope($connection)->whereIn('variants.id', $touched);
        $total = (clone $query)->count();
        $ids = $query->orderBy('variants.id')->forPage($page, $size)->pluck('variants.id')->map(fn ($id) => (string) $id)->all();

        return [$this->forVariants($connection, $ids), $total];
    }

    /**
     * The in-scope variants among these product ids (a variant parent's id stands for its variants).
     *
     * @param  list<string>  $productIds
     * @return list<string>
     */
    public function inScope(WorkshopConnection $connection, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return $this->variantsInScope($connection)
            ->where(fn (Builder $q) => $q->whereIn('variants.id', $productIds)->orWhereIn('variants.parent_product_id', $productIds))
            ->orderBy('variants.id')->pluck('variants.id')->map(fn ($id) => (string) $id)->all();
    }

    /** Variants (singles and variant children) whose top-level product is in scope. */
    private function variantsInScope(WorkshopConnection $connection): Builder
    {
        $scoped = ProductScope::for($connection)->apply(DB::table('products')->select('id')->where('company_id', $connection->company_id)->whereNull('parent_product_id'));

        return DB::table('products as variants')->where('variants.company_id', $connection->company_id)
            ->where('variants.is_variant_parent', false)->where('variants.is_active', true)->whereNull('variants.archived_at')->whereNull('variants.deleted_at')
            ->whereIn(DB::raw('COALESCE(variants.parent_product_id, variants.id)'), $scoped);
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, Product>  $products
     * @return list<array<string, mixed>>
     */
    private function rows(WorkshopConnection $connection, array $ids, array $products): array
    {
        if ($ids === []) {
            return [];
        }

        $stock = DB::table('branch_products')->where('company_id', $connection->company_id)->where('branch_id', $connection->branch_id)
            ->whereIn('product_id', $ids)->whereNull('deleted_at')->get(['product_id', 'qty_on_hand', 'qty_reserved', 'price_override', 'is_stock_tracked'])
            ->keyBy('product_id')->map(fn ($row) => (array) $row);
        $live = ShopPrices::live($ids, [$connection->branch_id]);
        $rows = [];

        foreach ($ids as $id) {
            $product = $products[$id];
            /** @var array<string, mixed> $row */
            $row = $stock->get($id, []);
            $tracked = ($row['is_stock_tracked'] ?? null) === null ? $product->track_stock : (bool) $row['is_stock_tracked'];
            $shelf = $live[$id][$connection->branch_id][''] ?? null;
            $price = $shelf instanceof BranchPrice ? $shelf->price : ($row['price_override'] ?? $product->sell_price);

            $rows[] = [
                'variant' => $id,
                'store' => $connection->branch_id,
                'quantity' => $tracked ? self::available($row['qty_on_hand'] ?? null, $row['qty_reserved'] ?? null) : null,
                'price' => (float) Money::normalise($price),
            ];
        }

        return $rows;
    }

    private static function available(mixed $onHand, mixed $reserved): float
    {
        $available = Money::sub($onHand ?? '0', $reserved ?? '0', Money::QUANTITY_SCALE);

        return Money::isNegative($available) ? 0.0 : (float) $available;
    }
}
