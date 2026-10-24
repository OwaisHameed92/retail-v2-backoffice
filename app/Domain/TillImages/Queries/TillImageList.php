<?php

namespace App\Domain\TillImages\Queries;

use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Sync\Support\SyncApiErrors;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillImages\Enums\ProductImageSource;
use App\Domain\TillImages\Models\ProductImage;

/**
 * `GET sync/product-images?after={productId}&max={n}` (contract proposal product-images §4.3): the till pictures the
 * portal holds for the calling business, one page in product id order, so the till's "Find pictures" sweep (and a
 * till upgrading with an old catalogue) uploads only what is missing or different. Keyset paging on the product id:
 * `after` = the last `productId` of the previous page (`nextAfter`), empty or missing the first time.
 */
final class TillImageList
{
    public const DEFAULT_MAX = 1000;

    public const MAX = 5000;

    /**
     * @return array{images: list<array{productId: string, imageHash: string, updatedAt: string}>, nextAfter: string|null, hasMore: bool}
     *
     * @throws ApiException 400 request.invalid on a bad `after` or `max`
     */
    public function handle(Company $company, mixed $after, mixed $max): array
    {
        $after = is_string($after) ? trim($after) : ($after === null ? '' : null);
        $max = $max === null || $max === '' ? self::DEFAULT_MAX : (is_string($max) && ctype_digit($max) ? (int) $max : 0);

        if ($after === null || ($after !== '' && preg_match('/^[0-9A-Za-z]{26}$/', $after) !== 1)) {
            throw SyncApiErrors::invalid('after must be a product id from nextAfter, or left out.');
        }

        if ($max < 1 || $max > self::MAX) {
            throw SyncApiErrors::invalid('max must be a whole number from 1 to '.self::MAX.'.');
        }

        $rows = app(CurrentCompany::class)->runAs($company, fn () => ProductImage::query()
            ->where('company_id', $company->id)->where('source', ProductImageSource::Till)
            ->when($after !== '', fn ($q) => $q->where('product_id', '>', $after))
            ->orderBy('product_id')->limit($max + 1)->get(['product_id', 'hash', 'updated_at']));

        $hasMore = $rows->count() > $max;
        $page = $rows->take($max)->values();

        return [
            'images' => $page->map(fn (ProductImage $image) => [
                'productId' => $image->product_id,
                'imageHash' => $image->hash,
                'updatedAt' => ($image->updated_at ?? now())->utc()->format('Y-m-d\TH:i:s\Z'),
            ])->all(),
            'nextAfter' => $hasMore ? $page->last()?->product_id : null,
            'hasMore' => $hasMore,
        ];
    }
}
