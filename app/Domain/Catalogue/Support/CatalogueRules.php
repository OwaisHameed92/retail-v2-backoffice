<?php

namespace App\Domain\Catalogue\Support;

use App\Domain\TillData\Models\Category;
use App\Domain\TillData\Models\Department;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductBarcode;
use App\Domain\TillData\Models\Unit;
use App\Domain\TillData\Models\VatRate;
use Illuminate\Validation\ValidationException;

/**
 * The product checks that need the business's data (SaveProduct). Every query runs in the company scope, so an id of
 * another business is simply "not found". Errors are keyed as the product form names its fields.
 */
final class CatalogueRules
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{id?: string|null, barcode: string, pack_qty?: int|string|null, is_primary?: bool|null}>|null  $barcodes
     * @param  list<array{unit_id: string}>|null  $units
     */
    public function check(Product $product, array $attributes, ?array $barcodes, ?array $units): void
    {
        $errors = [];
        $value = fn (string $key) => array_key_exists($key, $attributes) ? $attributes[$key] : $product->getAttribute($key);
        $departmentId = (string) $value('department_id');
        $categoryId = (string) $value('category_id');
        $subCategoryId = $value('sub_category_id');

        if (! Department::query()->whereKey($departmentId)->exists()) {
            $errors['department_id'] = 'Choose a department.';
        }

        $category = Category::query()->find($categoryId);

        if ($category === null) {
            $errors['category_id'] = 'Choose a category.';
        } elseif ($category->department_id !== $departmentId) {
            $errors['category_id'] = 'This category belongs to another department.';
        }

        if ($subCategoryId !== null && $subCategoryId !== ''
            && ! Category::query()->whereKey((string) $subCategoryId)->where('parent_category_id', $categoryId)->exists()) {
            $errors['sub_category_id'] = 'This sub-category is not under the chosen category.';
        }

        if (! VatRate::query()->whereKey((string) $value('vat_rate_id'))->exists()) {
            $errors['vat_rate_id'] = 'Choose a VAT rate.';
        }

        $sku = $attributes['sku'] ?? null;

        if (is_string($sku) && $sku !== '' && $sku !== $product->getOriginal('sku')
            && Product::query()->where('sku', $sku)->when($product->exists, fn ($q) => $q->whereKeyNot($product->id))->exists()) {
            $errors['sku'] = "Another product already has the code {$sku}.";
        }

        $errors += $this->barcodeErrors($product, $barcodes ?? []);

        $unitIds = array_values(array_unique(array_map(fn (array $u) => $u['unit_id'], $units ?? [])));
        $known = $unitIds === [] ? [] : Unit::query()->whereIn('id', $unitIds)->pluck('id')->all();

        foreach ($units ?? [] as $i => $unit) {
            if (! in_array($unit['unit_id'], $known, true)) {
                $errors["units.{$i}.unit_id"] = 'Choose a unit.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * A barcode may be on one product only (the till looks a scan up by it).
     *
     * @param  list<array{barcode: string}>  $barcodes
     * @return array<string, string>
     */
    private function barcodeErrors(Product $product, array $barcodes): array
    {
        $values = array_map(fn (array $b) => $b['barcode'], $barcodes);

        if ($values === []) {
            return [];
        }

        $taken = ProductBarcode::query()->whereIn('barcode', $values)
            ->when($product->exists, fn ($q) => $q->where('product_id', '!=', $product->id))
            ->pluck('product_id', 'barcode')->all();
        $names = $taken === [] ? [] : Product::query()->whereIn('id', array_values($taken))->pluck('name', 'id')->all();
        $errors = [];

        foreach ($values as $i => $barcode) {
            if (isset($taken[$barcode])) {
                $errors["barcodes.{$i}.barcode"] = "Barcode {$barcode} is already on ".($names[$taken[$barcode]] ?? 'another product').'.';
            } elseif (array_search($barcode, $values, true) !== $i) {
                $errors["barcodes.{$i}.barcode"] = "Barcode {$barcode} is listed twice.";
            }
        }

        return $errors;
    }
}
