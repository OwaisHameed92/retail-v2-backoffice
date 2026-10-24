<?php

namespace App\Domain\TillImages\Queries;

use App\Domain\TillImages\Enums\ProductImageSource;
use App\Domain\TillImages\Models\ProductImage;
use App\Domain\TillImages\Support\TillImageFiles;
use App\Domain\TillImages\Support\TillImageSwitch;

/**
 * The till's pictures of a business's products, for the portal's own pages (online store, Products list and form).
 * Nothing while TILL_PRODUCT_IMAGES is off (the table is not read). Runs inside the business's scope (a tenant request
 * or StoreScope) and filters on the company explicitly too.
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
     * @param  list<string>  $productIds
     * @return array<string, ProductImage> by product id
     */
    public static function images(string $companyId, array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter($productIds)));

        if (! TillImageSwitch::enabled() || $productIds === []) {
            return [];
        }

        $out = [];

        foreach (array_chunk($productIds, 500) as $chunk) {
            foreach (ProductImage::query()->where('company_id', $companyId)->where('source', ProductImageSource::Till)
                ->whereIn('product_id', $chunk)->get(['product_id', 'path', 'width', 'height', 'updated_at']) as $image) {
                $out[$image->product_id] = $image;
            }
        }

        return $out;
    }

    /**
     * One product's picture for the product form, or null.
     *
     * @return array{url: string, width: int, height: int, updatedAt: string|null}|null
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
        ];
    }
}
