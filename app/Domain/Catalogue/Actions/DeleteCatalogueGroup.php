<?php

namespace App\Domain\Catalogue\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\TillData\Models\Category;
use App\Domain\TillData\Models\Department;
use App\Domain\TillData\Models\Product;
use Illuminate\Validation\ValidationException;

/**
 * Deletes an empty department or category (soft delete: every till receives a `D`). Refused while anything still
 * files under it (categories, sub-categories or products, archived ones included); switch it off instead.
 */
final class DeleteCatalogueGroup
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(Department|Category $group): void
    {
        $inUse = $group instanceof Department
            ? Category::query()->where('department_id', $group->id)->exists() || Product::query()->where('department_id', $group->id)->exists()
            : Category::query()->where('parent_category_id', $group->id)->exists()
                || Product::query()->where(fn ($q) => $q->where('category_id', $group->id)->orWhere('sub_category_id', $group->id))->exists();

        if ($inUse) {
            throw ValidationException::withMessages(['group' => "{$group->name} still has ".($group instanceof Department ? 'categories or products' : 'sub-categories or products').'. Move them first, or switch it off instead.']);
        }

        $group->delete();

        $this->audit->handle($group instanceof Department ? 'department.deleted' : 'category.deleted', $group, ['name' => $group->name], null, ['name' => $group->name]);
    }
}
