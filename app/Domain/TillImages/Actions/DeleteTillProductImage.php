<?php

namespace App\Domain\TillImages\Actions;

use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Sync\Support\SyncApiErrors;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Product;
use App\Domain\TillImages\Enums\ProductImageSource;
use App\Domain\TillImages\Models\ProductImage;
use App\Domain\TillImages\Support\TillImageErrors;
use App\Domain\TillImages\Support\TillImageFiles;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * `DELETE sync/product-images/{productId}` (contract proposal product-images §4.2): the till removed a product's
 * picture. The till's picture row and its file go; a portal picture (later phase) is never touched. Idempotent: 200
 * `deleted`, or 200 `none` when the portal held no till picture. A product that is not the business's (or is
 * deleted) → 404 product.not_found, as for an upload.
 */
class DeleteTillProductImage
{
    /**
     * @return array{productId: string, result: 'deleted'|'none'}
     *
     * @throws ApiException
     */
    public function handle(Company $company, string $productId): array
    {
        return app(CurrentCompany::class)->runAs($company, function () use ($company, $productId): array {
            if (! Product::query()->where('company_id', $company->id)->whereKey($productId)->exists()) {
                throw TillImageErrors::productNotFound($productId);
            }

            try {
                $path = Cache::lock("till-product-image:{$company->id}:{$productId}", 30)->block(10, function () use ($company, $productId): ?string {
                    $image = ProductImage::query()->where('company_id', $company->id)->where('product_id', $productId)
                        ->where('source', ProductImageSource::Till)->first();
                    $image?->delete();

                    return $image?->path;
                });
            } catch (LockTimeoutException) {
                throw SyncApiErrors::busy(5);
            }

            if ($path === null) {
                return ['productId' => $productId, 'result' => 'none'];
            }

            TillImageFiles::delete($company->id, $path);
            Log::info('Till product picture deleted', ['company_id' => $company->id, 'product_id' => $productId]);

            return ['productId' => $productId, 'result' => 'deleted'];
        });
    }
}
