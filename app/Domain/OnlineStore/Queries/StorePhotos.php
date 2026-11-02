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
use App\Domain\TillImages\Enums\ProductImageOrigin;
use App\Domain\TillImages\Enums\ProductImageSource;
use App\Domain\TillImages\Queries\TillPictures;
use App\Domain\TillImages\Support\TillImageFiles;
use App\Domain\TillImages\Support\TillImageSwitch;
use Illuminate\Database\Query\JoinClause;

/**
 * The photos the public store shows (OS-8, IMG-1). A product's photo is its online photo (store builder, public disk),
 * else the till's picture (uploaded by the till, `product_images`, only while TILL_PRODUCT_IMAGES is on), else the
 * master catalogue's picture of one of its barcodes (`master_products.image_url`, any UPC/EAN form), else none (the
 * page draws a branded placeholder). The till's own `image_path` is a path on the till's PC, never a URL. A
 * department's photo is the photo of one of its products shown at the shop, in the same order.
 *
 * IMG-2: a till picture is used only when its origin is the shop's own or Open Food Facts (credited, `credit`), or any
 * origin when the store's "Show found pictures" is on (ProductImageOrigin::shownOnStore). A hidden till picture falls
 * through to the master catalogue, then none, exactly as if there were no till picture.
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
        return array_map(fn (array $photo) => $photo['url'], $this->productsWithCredit($store, $productIds));
    }

    /**
     * IMG-2: the photos with the credit to show beside them (an Open Food Facts till picture), by product id.
     *
     * @param  list<string>  $productIds
     * @return array<string, array{url: string, credit: string|null}>
     */
    public function productsWithCredit(OnlineStore $store, array $productIds): array
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
            $till = TillPictures::forStore($store->company_id, $missing, (bool) $store->show_found_pictures);
            $missing = array_values(array_diff($missing, array_keys($till)));
            $master = $missing === [] ? [] : $this->masterImages($store, $missing);
            $plain = fn (array $urls) => array_map(fn (string $url) => ['url' => $url, 'credit' => null], $urls);

            return $plain($own) + $till + $plain($master);
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
            $own += $missing === [] ? [] : $this->tillDepartments($store, $shop, $missing, $column);
            $missing = array_values(array_diff($missing, array_keys($own)));

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
     * One till picture per department (IMG-1), by department id; nothing while TILL_PRODUCT_IMAGES is off. IMG-2: only
     * the origins the store shows.
     *
     * @param  list<string>  $departmentIds
     * @return array<string, string>
     */
    private function tillDepartments(OnlineStore $store, OnlineStoreShop $shop, array $departmentIds, string $column): array
    {
        if (! TillImageSwitch::enabled()) {
            return [];
        }

        return OnlineCatalogueQuery::base($shop)->whereIn("products.{$column}", $departmentIds)
            ->join('product_images as pi', fn (JoinClause $j) => $j->on('pi.product_id', '=', 'products.id')
                ->where('pi.company_id', '=', $store->company_id)->where('pi.source', '=', ProductImageSource::Till->value)
                ->whereIn('pi.origin', ProductImageOrigin::shownOnStore((bool) $store->show_found_pictures)))
            ->groupBy("products.{$column}")->selectRaw("products.{$column} as department_id, MIN(pi.path) as photo")
            ->toBase()->pluck('photo', 'department_id')
            ->map(fn ($path) => TillImageFiles::url((string) $path))->filter()->all();
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
