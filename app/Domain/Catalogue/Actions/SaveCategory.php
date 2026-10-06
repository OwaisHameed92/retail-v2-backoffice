<?php

namespace App\Domain\Catalogue\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Country\Country;
use App\Domain\TillData\Models\Category;
use App\Domain\TillData\Models\Department;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\VatRate;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Creates or edits a category or sub-category (hub-owned: every till receives it). Two levels, as a product has a
 * category and an optional sub-category: a sub-category's parent is a top-level category of the same department.
 * Names are unique under the same parent. A category that has products or sub-categories cannot move to another
 * department (its products would point at the wrong one). Keeps the category's ULID.
 */
final class SaveCategory
{
    public const FIELDS = [
        'department_id', 'parent_category_id', 'name', 'position', 'colour_hex', 'is_active', 'is_visible_on_till',
        'age_rule_default', 'default_vat_rate_id', 'negative_stock_mode',
    ];

    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function handle(?Category $category, array $attributes): Category
    {
        $attributes = Arr::only($attributes, self::FIELDS);
        $created = $category === null;
        $category ??= (new Category)->forceFill(['colour_hex' => '#1F6FEB', 'is_active' => true, 'is_visible_on_till' => true, 'age_rule_default' => 'none']);
        $departmentId = (string) ($attributes['department_id'] ?? $category->department_id);
        $parentId = array_key_exists('parent_category_id', $attributes) ? $attributes['parent_category_id'] : $category->parent_category_id;
        $name = trim((string) ($attributes['name'] ?? $category->name));
        $errors = [];

        if (! Department::query()->whereKey($departmentId)->exists()) {
            $errors['department_id'] = 'Choose a department.';
        }

        if ($parentId !== null) {
            $parent = Category::query()->find($parentId);

            if ($parent === null || $parent->parent_category_id !== null || $parent->department_id !== $departmentId || $parent->id === $category->id) {
                $errors['parent_category_id'] = 'Choose a top-level category of the same department.';
            } elseif (! $created && Category::query()->where('parent_category_id', $category->id)->exists()) {
                $errors['parent_category_id'] = 'This category has sub-categories, so it must stay at the top level.';
            }
        }

        $moved = $departmentId !== $category->department_id || $parentId !== $category->parent_category_id;

        if (! $created && $moved && $this->inUse($category)) {
            $errors['department_id'] = 'This category has products or sub-categories: re-file them before moving it.';
        }

        $vat = $attributes['default_vat_rate_id'] ?? null;

        if ($vat !== null && ! VatRate::query()->whereKey($vat)->exists()) {
            $errors['default_vat_rate_id'] = Country::tax('Choose a VAT rate.');
        }

        $clash = Category::query()->where('department_id', $departmentId)->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($parentId === null, fn ($q) => $q->whereNull('parent_category_id'), fn ($q) => $q->where('parent_category_id', $parentId))
            ->when(! $created, fn ($q) => $q->whereKeyNot($category->id))->exists();

        if ($clash) {
            $errors['name'] = "There is already a category called {$name} here.";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $category->forceFill([...$attributes, 'name' => $name, 'department_id' => $departmentId, 'parent_category_id' => $parentId]);

        if ($created) {
            $category->position ??= (int) Category::query()->where('department_id', $departmentId)->max('position') + 1;
        }

        if ($created || $category->isDirty()) {
            if (! $created) {
                $category->row_version = (int) $category->row_version + 1;
            }

            $category->save();
            $this->audit->handle($created ? 'category.created' : 'category.updated', $category, null, ['name' => $category->name], ['name' => $category->name]);
        }

        return $category;
    }

    private function inUse(Category $category): bool
    {
        return Category::query()->where('parent_category_id', $category->id)->exists()
            || Product::query()->where(fn ($q) => $q->where('category_id', $category->id)->orWhere('sub_category_id', $category->id))->exists();
    }
}
