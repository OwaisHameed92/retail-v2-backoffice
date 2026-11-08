<?php

namespace App\Domain\Workshop\Queries;

use App\Domain\Shared\Support\ApiDate;
use App\Domain\TillData\Models\Category;
use App\Domain\TillData\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Our product as BikeBook's `Product` + `variants[]` (WORKSHOP-BIKEBOOK.md §3.3 mapping table). A product without
 * variants has one variant, itself; `variants[].id` is the id BikeBook sends back on order lines. Cost is ex VAT;
 * prices are in the Inventory answer. Pictures: none yet (`images: []`) until the portal serves product pictures to
 * third parties over HTTPS.
 */
final class ProductModel
{
    /**
     * @param  array<string, string>  $categories  id => name
     * @param  array<string, string>  $suppliers  product id => supplier name
     * @param  array<string, string>  $barcodes  product id => primary barcode
     */
    private function __construct(private readonly array $categories, private readonly array $suppliers, private readonly array $barcodes) {}

    /**
     * @param  list<string>  $variantIds  every product id on the page (parents, singles and variants)
     * @param  list<Product>  $products  the top-level products
     */
    public static function load(array $variantIds, array $products): self
    {
        $categoryIds = array_values(array_unique(array_filter(array_merge(
            array_map(fn (Product $p) => $p->category_id, $products),
            array_map(fn (Product $p) => $p->sub_category_id, $products),
        ))));
        $categories = Category::query()->whereIn('id', $categoryIds)->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
        $suppliers = [];
        $barcodes = [];

        foreach (array_chunk($variantIds, 500) as $chunk) {
            $rows = DB::table('product_suppliers')->join('suppliers', 'suppliers.id', '=', 'product_suppliers.supplier_id')
                ->whereIn('product_suppliers.product_id', $chunk)->whereNull('product_suppliers.deleted_at')
                ->orderByDesc('product_suppliers.is_preferred')->orderBy('product_suppliers.id')
                ->get(['product_suppliers.product_id', 'suppliers.name']);

            foreach ($rows as $row) {
                $suppliers[(string) $row->product_id] ??= (string) $row->name;
            }

            $codes = DB::table('product_barcodes')->whereIn('product_id', $chunk)->whereNull('deleted_at')
                ->orderByDesc('is_primary')->orderBy('id')->get(['product_id', 'barcode']);

            foreach ($codes as $row) {
                $barcodes[(string) $row->product_id] ??= (string) $row->barcode;
            }
        }

        return new self($categories, $suppliers, $barcodes);
    }

    /**
     * @param  list<Product>  $variants
     * @return array<string, mixed>
     */
    public function product(Product $product, array $variants, ?CarbonInterface $updated, bool $enabled): array
    {
        $category = $product->sub_category_id !== null && isset($this->categories[$product->sub_category_id])
            ? $this->categories[$product->sub_category_id]
            : ($this->categories[$product->category_id] ?? '');

        return [
            'id' => $product->id,
            'sku' => (string) $product->sku,
            'name' => $product->name,
            'description' => (string) $product->description,
            'brand' => (string) $product->brand,
            'supplier' => $this->suppliers[$product->id] ?? ($variants === [] ? '' : ($this->suppliers[$variants[0]->id] ?? '')),
            'category' => $category,
            'created' => ApiDate::format($product->created_at),
            'updated' => ApiDate::format($updated ?? $product->updated_at),
            'version' => (string) $product->row_version,
            'enabled' => $enabled,
            'images' => [],
            'variants' => array_map(fn (Product $variant, int $i) => $this->variant($variant, $i + 1), $variants, array_keys($variants)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function variant(Product $variant, int $position): array
    {
        $size = null;
        $colour = null;
        $model = [];

        foreach ([1, 2, 3, 4] as $n) {
            $name = strtolower(trim((string) $variant->getAttribute("variant{$n}_name")));
            $value = trim((string) $variant->getAttribute("variant{$n}_value"));

            if ($value === '') {
                continue;
            }

            match (true) {
                $name === 'size' && $size === null => $size = $value,
                in_array($name, ['colour', 'color'], true) && $colour === null => $colour = $value,
                default => $model[] = $value,
            };
        }

        return [
            'id' => $variant->id,
            'sku' => (string) $variant->sku,
            'barcode' => $this->barcodes[$variant->id] ?? '',
            'size' => $size,
            'colour' => $colour,
            'model' => $model === [] ? null : implode(' ', $model),
            'weight' => $variant->net_mass_kg === null ? null : (float) $variant->net_mass_kg,
            'cost' => (float) $variant->cost_price,
            'sizeOrder' => $position,
        ];
    }
}
