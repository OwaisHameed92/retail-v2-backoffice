<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Models\OnlineStoreProduct;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Product;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * How one of the business's till products shows online: hidden or shown, a short description, a photo (the path of
 * a file OS-3 uploads), and (OS-11) `not_featured`: kept out of the automatic featured row; (OS-13) `out_of_stock` and
 * `stock_count`, the shop's own stock used only while the business has no live till (ManualStock). Only the keys given change (`hidden`, `not_featured`, `description`, `photo_path`); the till's product row is
 * never touched. The product must be one of this business's (not removed). Nothing is written when nothing changes.
 * Audited.
 *
 *     app(SetOnlineProduct::class)->handle($company, $productId, ['hidden' => true]);
 */
final class SetOnlineProduct
{
    public const DESCRIPTION_MAX = 1000;

    /** OS-13: the largest stock count the shop can enter. */
    public const STOCK_MAX = 999999;

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
            $row ??= new OnlineStoreProduct(['company_id' => $company->id, 'product_id' => $product->id, 'hidden' => false, 'not_featured' => false, 'out_of_stock' => false]);
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
        $changes = array_intersect_key($changes, array_flip(['hidden', 'not_featured', 'description', 'photo_path', 'allergen_info', 'out_of_stock', 'stock_count']));

        $data = Validator::make($changes, [
            'hidden' => ['sometimes', 'boolean'],
            'not_featured' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.self::DESCRIPTION_MAX],
            'allergen_info' => ['sometimes', 'nullable', 'string', 'max:'.self::DESCRIPTION_MAX],
            'photo_path' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^(?!\/)(?!.*\.\.)[A-Za-z0-9_\/.-]+$/'],
            // OS-13: the shop's own stock, used only while the business has no live till (ManualStock).
            'out_of_stock' => ['sometimes', 'boolean'],
            'stock_count' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:'.self::STOCK_MAX],
        ], [
            'photo_path.regex' => 'That file path is not allowed.',
            'stock_count.numeric' => 'Enter the stock as a number, or leave it blank.',
            'stock_count.min' => 'The stock cannot be below 0.',
            'stock_count.max' => 'Enter up to '.self::STOCK_MAX.'.',
        ])->validate();

        if (array_key_exists('stock_count', $data)) {
            $data['stock_count'] = $data['stock_count'] === null || $data['stock_count'] === '' ? null : Money::normalise($data['stock_count'], 4);
        }

        foreach (['hidden', 'not_featured', 'out_of_stock'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = (bool) $data[$flag];
            }
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
     * @return array{hidden: bool, not_featured: bool, description: string|null, photo_path: string|null, allergen_info: string|null, out_of_stock: bool, stock_count: string|null}
     */
    private static function snapshot(OnlineStoreProduct $row): array
    {
        return ['hidden' => (bool) $row->hidden, 'not_featured' => (bool) $row->not_featured, 'description' => $row->description, 'photo_path' => $row->photo_path, 'allergen_info' => $row->allergen_info,
            'out_of_stock' => (bool) $row->out_of_stock, 'stock_count' => $row->stock_count === null ? null : Money::normalise((string) $row->stock_count, 4)];
    }
}
