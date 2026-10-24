<?php

namespace App\Domain\TillImages\Actions;

use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Sync\Support\SyncApiErrors;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Product;
use App\Domain\TillImages\Data\TillImageUpload;
use App\Domain\TillImages\Enums\ProductImageSource;
use App\Domain\TillImages\Models\ProductImage;
use App\Domain\TillImages\Support\ImageReencoder;
use App\Domain\TillImages\Support\TillImageErrors;
use App\Domain\TillImages\Support\TillImageFiles;
use App\Domain\TillImages\Support\TillImageSwitch;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * `PUT sync/product-images/{productId}` (contract proposal product-images §4.1): a till's picture of one of its
 * business's products.
 *
 * 1. The product must be the calling business's and not deleted → else 404 product.not_found (another business's
 *    product looks exactly like an unknown one).
 * 2. Size, then `X-SSPOS-Image-Sha256` must be the sha256 of the bytes → 413 image.too_large / 422 image.hash_mismatch.
 * 3. Same hash as the picture held → 200 `unchanged`, nothing written (idempotent: retries and the "Find pictures"
 *    sweep cost nothing).
 * 4. Else the picture is checked and re-encoded (ImageReencoder: type, size in pixels, EXIF gone), the web copy is
 *    written under a new name, the row upserted, and the old file removed → `stored` / `replaced`.
 *
 * One product at a time (cache lock), so two uploads of one product never leave two files. Logged without the bytes.
 */
class StoreTillProductImage
{
    public function __construct(private readonly ImageReencoder $encoder) {}

    /**
     * @return array{productId: string, imageHash: string, result: 'stored'|'replaced'|'unchanged', updatedAt: string}
     *
     * @throws ApiException
     */
    public function handle(Company $company, TillImageUpload $upload): array
    {
        return app(CurrentCompany::class)->runAs($company, function () use ($company, $upload): array {
            $productId = $upload->productId;

            if (! Product::query()->where('company_id', $company->id)->whereKey($productId)->exists()) {
                throw TillImageErrors::productNotFound($productId);
            }

            if (strlen($upload->bytes) > TillImageSwitch::maxBytes()) {
                throw TillImageErrors::tooLarge($productId);
            }

            if (! hash_equals(hash('sha256', $upload->bytes), $upload->sha256)) {
                throw TillImageErrors::hashMismatch($productId);
            }

            if (($held = $this->held($company, $productId)) !== null && $held->hash === $upload->sha256) {
                return $this->reply($held, 'unchanged');
            }

            $encoded = $this->encoder->encode($productId, $upload->bytes, $upload->contentType);

            try {
                return Cache::lock("till-product-image:{$company->id}:{$productId}", 30)->block(10, function () use ($company, $upload, $encoded): array {
                    $held = $this->held($company, $upload->productId);

                    if ($held !== null && $held->hash === $upload->sha256) {
                        return $this->reply($held, 'unchanged');
                    }

                    $path = TillImageFiles::path($company->id, $upload->productId, $upload->sha256, $encoded->extension);

                    if (! TillImageFiles::put($path, $encoded->bytes)) {
                        throw SyncApiErrors::busy(30);
                    }

                    $old = $held?->path;
                    $image = $held ?? new ProductImage(['company_id' => $company->id, 'product_id' => $upload->productId, 'source' => ProductImageSource::Till]);
                    $image->fill(['path' => $path, 'hash' => $upload->sha256, 'bytes' => strlen($encoded->bytes), 'width' => $encoded->width, 'height' => $encoded->height])->save();

                    if ($old !== null && $old !== $path) {
                        TillImageFiles::delete($company->id, $old);
                    }

                    $result = $held === null ? 'stored' : 'replaced';
                    Log::info('Till product picture '.$result, ['company_id' => $company->id, 'product_id' => $upload->productId,
                        'hash' => substr($upload->sha256, 0, 16), 'bytes' => strlen($upload->bytes), 'stored_bytes' => strlen($encoded->bytes)]);

                    return $this->reply($image, $result);
                });
            } catch (LockTimeoutException) {
                throw SyncApiErrors::busy(5);
            }
        });
    }

    private function held(Company $company, string $productId): ?ProductImage
    {
        return ProductImage::query()->where('company_id', $company->id)->where('product_id', $productId)
            ->where('source', ProductImageSource::Till)->first();
    }

    /**
     * @param  'stored'|'replaced'|'unchanged'  $result
     * @return array{productId: string, imageHash: string, result: 'stored'|'replaced'|'unchanged', updatedAt: string}
     */
    private function reply(ProductImage $image, string $result): array
    {
        return [
            'productId' => $image->product_id,
            'imageHash' => $image->hash,
            'result' => $result,
            'updatedAt' => ($image->updated_at ?? now())->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
