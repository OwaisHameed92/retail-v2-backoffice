<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\MasterCatalogue\Models\MasterProduct;
use App\Domain\MasterCatalogue\Support\Gtin;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreProduct;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Support\OnlineCatalogueQuery;
use App\Domain\OnlineStore\Support\StoreImages;
use App\Domain\OnlineStore\Support\StoreScope;
use App\Domain\TillData\Models\ProductBarcode;
use Illuminate\Database\Query\JoinClause;

/**
 * The photos the public store shows (OS-8). A product's photo is its online photo (store builder, public disk), else
 * the master catalogue's picture of one of its barcodes (`master_products.image_url`, any UPC/EAN form), else none
 * (the page draws a branded placeholder). The till's own `image_path` is a path on the till's PC, never a URL. A
 * department's photo is the photo of one of its products shown at the shop (online photos first).
 *
 * Reads only: the business's rows filtered explicitly (StoreScope too); the master catalogue is platform data.
 */
final class StorePhotos
{
    /**
     * Photo addresses of these products, by product id (products without one are left out).
     *
     * @param  list<string>  $productIds
     * @return array<string, string>
     */
    public function products(OnlineStore $store, array $productIds): array
    {
        $productIds = array_values(array_unique($productIds));

        if ($productIds === []) {
            return [];
        }

        return StoreScope::run($store, function () use ($store, $productIds): array {
            $own = OnlineStoreProduct::query()->where('company_id', $store->company_id)->whereIn('product_id', $productIds)
                ->whereNotNull('photo_path')->where('photo_path', '!=', '')->pluck('photo_path', 'product_id')
                ->map(fn ($path) => StoreImages::url((string) $path))->filter()->all();
            $missing = array_values(array_diff($productIds, array_keys($own)));

            return $own + ($missing === [] ? [] : $this->masterImages($store, $missing));
        });
    }

    /**
     * One photo per department (of a product shown at the shop), by department id; departments without one are left out.
     * OS-11: `$column` `category_id` gives one per category (category aisles).
     *
     * @param  list<string>  $departmentIds
     * @return array<string, string>
     */
    public function departments(OnlineStore $store, OnlineStoreShop $shop, array $departmentIds, string $column = 'department_id'): array
    {
        if ($departmentIds === []) {
            return [];
        }

        $column = $column === 'category_id' ? 'category_id' : 'department_id';

        return StoreScope::run($store, function () use ($store, $shop, $departmentIds, $column): array {
            $own = OnlineCatalogueQuery::base($shop)->whereIn("products.{$column}", $departmentIds)
                ->whereNotNull('osp.photo_path')->where('osp.photo_path', '!=', '')
                ->groupBy("products.{$column}")->selectRaw("products.{$column} as department_id, MIN(osp.photo_path) as photo")
                ->toBase()->pluck('photo', 'department_id')
                ->map(fn ($path) => StoreImages::url((string) $path))->filter()->all();
            $missing = array_values(array_diff($departmentIds, array_keys($own)));

            if ($missing === []) {
                return $own;
            }

            $master = OnlineCatalogueQuery::base($shop)->whereIn("products.{$column}", $missing)
                ->join('product_barcodes as pbp', fn (JoinClause $j) => $j->on('pbp.product_id', '=', 'products.id')
                    ->where('pbp.company_id', '=', $store->company_id)->whereNull('pbp.deleted_at'))
                ->join('master_products as mp', 'mp.barcode', '=', 'pbp.barcode')
                ->whereNotNull('mp.image_url')->where('mp.image_url', '!=', '')
                ->groupBy("products.{$column}")->selectRaw("products.{$column} as department_id, MIN(mp.image_url) as photo")
                ->toBase()->pluck('photo', 'department_id')
                ->map(fn ($url) => self::safeUrl((string) $url))->filter()->all();

            return $own + $master;
        });
    }

    /**
     * The master catalogue's picture of one of each product's barcodes (a merged entry gives way to
     * the product it was merged into when that has a picture).
     *
     * @param  list<string>  $productIds
     * @return array<string, string>
     */
    private function masterImages(OnlineStore $store, array $productIds): array
    {
        $barcodes = ProductBarcode::query()->where('company_id', $store->company_id)->whereIn('product_id', $productIds)
            ->whereNotNull('barcode')->orderByRaw('is_primary IS NULL')->orderByDesc('is_primary')->orderBy('id')
            ->get(['product_id', 'barcode']);
        $codes = [];

        foreach ($barcodes as $row) {
            $code = Gtin::normalise((string) $row->barcode);

            if ($code !== null && ! Gtin::isInStore($code)) {
                foreach (Gtin::variants($code) as $variant) {
                    $codes[$variant][] = (string) $row->product_id;
                }
            }
        }

        if ($codes === []) {
            return [];
        }

        $masters = MasterProduct::query()->whereIn('barcode', array_keys($codes))->get(['id', 'barcode', 'image_url', 'merged_into_id']);
        $targets = MasterProduct::query()->whereIn('id', $masters->pluck('merged_into_id')->filter()->unique()->values()->all())
            ->pluck('image_url', 'id');
        $out = [];

        foreach ($masters as $master) {
            $url = self::safeUrl((string) ($master->merged_into_id !== null ? ($targets[$master->merged_into_id] ?? $master->image_url) : $master->image_url));

            foreach ($url === null ? [] : $codes[$master->barcode] ?? [] as $productId) {
                $out[$productId] ??= $url;
            }
        }

        return $out;
    }

    /** Only http(s) addresses or paths on this site are used as pictures. */
    private static function safeUrl(string $url): ?string
    {
        $url = trim($url);

        return preg_match('#^(https?://|/)[^\s"\'<>]+$#i', $url) === 1 && ! str_starts_with($url, '//') ? $url : null;
    }
}
