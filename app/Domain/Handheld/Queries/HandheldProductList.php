<?php

namespace App\Domain\Handheld\Queries;

use App\Domain\Handheld\Support\PdaPage;
use App\Domain\Handheld\Support\ShopProductFacts;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductBarcode;
use Illuminate\Database\Eloquent\Builder;

/**
 * `GET /api/pda/products`: the business's active products (the catalogue is company-wide) with the user's shop's
 * price and stock. `barcode` = exact match on a product barcode (a scan); `search` = part of the name, SKU or a
 * barcode. Ordered by name.
 */
final class HandheldProductList
{
    /**
     * @return array{data: list<array<string, mixed>>, current_page: int, last_page: int, per_page: int, total: int}
     */
    public function handle(Branch $shop, ?string $search, ?string $barcode, int $perPage, int $page): array
    {
        $search = trim((string) $search);
        $barcode = trim((string) $barcode);

        $query = Product::query()->where('is_active', true)
            ->when($barcode !== '', fn (Builder $q) => $q->whereIn('id', ProductBarcode::query()->select('product_id')->where('barcode', $barcode)))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")
                ->orWhereIn('id', ProductBarcode::query()->select('product_id')->where('barcode', 'like', "%{$search}%"))))
            ->orderBy('name')->orderBy('id');

        return PdaPage::of($query, $perPage, $page, function ($products) use ($shop) {
            $facts = new ShopProductFacts($shop, $products->pluck('id')->all());

            return $products->map(fn (Product $product) => $facts->row($product))->values()->all();
        });
    }
}
