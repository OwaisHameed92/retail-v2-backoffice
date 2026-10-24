<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Models\OnlineStoreProduct;
use App\Domain\OnlineStore\Support\StoreImages;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The products page's "Online details" dialog (OS-3): shown or hidden, short description, ingredients and allergens
 * (OS-8, audit OSA-3) and photo, through
 * SetOnlineProduct. A new photo is stored first (StoreImages) and replaces the old one, which is then deleted;
 * `remove_photo` takes it off. The product must be one of the business's own (checked before anything is stored).
 *
 *     app(SaveOnlineProductDetails::class)->handle($company, $productId, ['hidden' => false, 'description' => '…'], $photo);
 */
final class SaveOnlineProductDetails
{
    public function __construct(private readonly SetOnlineProduct $set) {}

    /**
     * @param  array<string, mixed>  $changes  `hidden`, `description`, `remove_photo`
     *
     * @throws ValidationException
     */
    public function handle(Company $company, string $productId, array $changes, ?UploadedFile $photo = null): OnlineStoreProduct
    {
        if (! Product::withoutCompanyScope()->where('company_id', $company->id)->whereKey($productId)->exists()) {
            throw ValidationException::withMessages(['product_id' => 'Choose one of your products.']);
        }

        $old = OnlineStoreProduct::withoutCompanyScope()->where('company_id', $company->id)->where('product_id', $productId)->value('photo_path');
        $changes = array_intersect_key($changes, array_flip(['hidden', 'description', 'allergen_info', 'remove_photo']));
        $new = null;

        if ($photo !== null) {
            $changes['photo_path'] = $new = StoreImages::put($company, $photo, StoreImages::PRODUCT, 'photo');
        } elseif ((bool) ($changes['remove_photo'] ?? false)) {
            $changes['photo_path'] = null;
        }

        unset($changes['remove_photo']);

        try {
            $row = $this->set->handle($company, $productId, $changes);
        } catch (Throwable $e) {
            StoreImages::delete($company, $new);

            throw $e;
        }

        if (is_string($old) && $old !== $row->photo_path) {
            StoreImages::delete($company, $old);
        }

        return $row;
    }
}
