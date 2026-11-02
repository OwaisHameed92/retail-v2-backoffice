<?php

namespace App\Domain\TillImages\Queries;

use App\Domain\TillImages\Enums\ProductImageOrigin;
use App\Domain\TillImages\Enums\ProductImageSource;
use App\Domain\TillImages\Models\ProductImage;
use App\Domain\TillImages\Support\TillImageFiles;
use App\Domain\TillImages\Support\TillImageSwitch;

/**
 * The till's pictures of a business's products, for the portal's own pages (online store, Products list and form).
 * Nothing while TILL_PRODUCT_IMAGES is off (the table is not read). Runs inside the business's scope (a tenant request
 * or StoreScope) and filters on the company explicitly too.
 *
 * IMG-2: the portal's own pages (urls, forProduct) show every picture whatever its origin; the public store
 * (forStore) shows only the origins ProductImageOrigin::shownOnStore allows, a hidden one is as if there were none.
 */
final class TillPictures
{
    /**
     * Picture addresses by product id (products without one are left out).
     *
     * @param  list<string>  $productIds
     * @return array<string, string>
     */
    public static function urls(string $companyId, array $productIds): array
    {
        return array_filter(array_map(fn (ProductImage $image) => TillImageFiles::url($image->path), self::images($companyId, $productIds)));
    }

    /**
     * The public store's till pictures (IMG-2): only the origins shown on the store, with the credit to show (Open Food
     * Facts), by product id.
     *
     * @param  list<string>  $productIds
     * @return array<string, array{url: string, credit: string|null}>
     */
    public static function forStore(string $companyId, array $productIds, bool $showFound): array
    {
        $out = [];

        foreach (self::images($companyId, $productIds, ProductImageOrigin::shownOnStore($showFound)) as $productId => $image) {
            if (($url = TillImageFiles::url($image->path)) !== null) {
                $out[$productId] = ['url' => $url, 'credit' => $image->origin->credit()];
            }
        }

        return $out;
    }

    /**
     * Whether the business holds an Open Food Facts picture (IMG-2: the store footer's credit line).
     */
    public static function hasLibrary(string $companyId): bool
    {
        return TillImageSwitch::enabled() && ProductImage::query()->where('company_id', $companyId)->where('source', ProductImageSource::Till)
            ->where('origin', ProductImageOrigin::Library)->exists();
    }

    /**
     * @param  list<string>  $productIds
     * @param  list<string>|null  $origins  IMG-2: only these origins (null = all)
     * @return array<string, ProductImage> by product id
     */
    public static function images(string $companyId, array $productIds, ?array $origins = null): array
    {
        $productIds = array_values(array_unique(array_filter($productIds)));

        if (! TillImageSwitch::enabled() || $productIds === []) {
            return [];
        }

        $out = [];

        foreach (array_chunk($productIds, 500) as $chunk) {
            foreach (ProductImage::query()->where('company_id', $companyId)->where('source', ProductImageSource::Till)
                ->whereIn('product_id', $chunk)->when($origins !== null, fn ($q) => $q->whereIn('origin', $origins))
                ->get(['product_id', 'path', 'origin', 'width', 'height', 'updated_at']) as $image) {
                $out[$image->product_id] = $image;
            }
        }

        return $out;
    }

    /**
     * One product's picture for the product form, or null.
     *
     * @return array{url: string, width: int, height: int, updatedAt: string|null, origin: string, originLabel: string}|null
     */
    public static function forProduct(string $companyId, string $productId): ?array
    {
        $image = self::images($companyId, [$productId])[$productId] ?? null;
        $url = TillImageFiles::url($image?->path);

        return $image === null || $url === null ? null : [
            'url' => $url,
            'width' => $image->width,
            'height' => $image->height,
            'updatedAt' => $image->updated_at?->toIso8601ZuluString(),
            // IMG-2: where the till got it, shown as a small label on the product form.
            'origin' => $image->origin->value,
            'originLabel' => $image->origin->label(),
        ];
    }
}
