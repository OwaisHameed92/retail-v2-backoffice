<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Models\OnlineStoreProduct;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Product;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * How one of the business's till products shows online: hidden or shown, a short description, a photo (the path of
 * a file OS-3 uploads). Only the keys given change (`hidden`, `description`, `photo_path`); the till's product row is
 * never touched. The product must be one of this business's (not removed). Nothing is written when nothing changes.
 * Audited.
 *
 *     app(SetOnlineProduct::class)->handle($company, $productId, ['hidden' => true]);
 */
final class SetOnlineProduct
{
    public const DESCRIPTION_MAX = 1000;

    public function __construct(private readonly RecordAudit $audit, private readonly CurrentCompany $tenancy) {}

    /**
     * @param  array<string, mixed>  $changes
     *
     * @throws ValidationException
     */
    public function handle(Company $company, string $productId, array $changes): OnlineStoreProduct
    {
        $changes = self::validate($changes);

        return $this->tenancy->runAs($company, function () use ($company, $productId, $changes): OnlineStoreProduct {
            $product = Product::query()->where('company_id', $company->id)->whereKey($productId)->first();

            if ($product === null) {
                throw ValidationException::withMessages(['product_id' => 'Choose one of your products.']);
            }

            $row = OnlineStoreProduct::query()->where('company_id', $company->id)->where('product_id', $product->id)->first();
            $before = $row === null ? null : self::snapshot($row);
            $row ??= new OnlineStoreProduct(['company_id' => $company->id, 'product_id' => $product->id, 'hidden' => false]);
            $row->fill($changes);

            if ($row->exists && ! $row->isDirty()) {
                return $row;
            }

            $row->save();
            $this->audit->handle('online_store.product_updated', $row, $before, self::snapshot($row), ['product' => $product->name], companyId: $company->id);

            return $row->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function validate(array $changes): array
    {
        $changes = array_intersect_key($changes, array_flip(['hidden', 'description', 'photo_path', 'allergen_info']));

        $data = Validator::make($changes, [
            'hidden' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.self::DESCRIPTION_MAX],
            'allergen_info' => ['sometimes', 'nullable', 'string', 'max:'.self::DESCRIPTION_MAX],
            'photo_path' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^(?!\/)(?!.*\.\.)[A-Za-z0-9_\/.-]+$/'],
        ], ['photo_path.regex' => 'That file path is not allowed.'])->validate();

        if (array_key_exists('hidden', $data)) {
            $data['hidden'] = (bool) $data['hidden'];
        }

        foreach (['description', 'photo_path', 'allergen_info'] as $key) {
            if (array_key_exists($key, $data)) {
                $value = trim((string) $data[$key]);
                $data[$key] = $value === '' ? null : $value;
            }
        }

        return $data;
    }

    /**
     * @return array{hidden: bool, description: string|null, photo_path: string|null, allergen_info: string|null}
     */
    private static function snapshot(OnlineStoreProduct $row): array
    {
        return ['hidden' => (bool) $row->hidden, 'description' => $row->description, 'photo_path' => $row->photo_path, 'allergen_info' => $row->allergen_info];
    }
}
