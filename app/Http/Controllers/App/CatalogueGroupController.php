<?php

namespace App\Http\Controllers\App;

use App\Domain\Catalogue\Actions\DeleteCatalogueGroup;
use App\Domain\Catalogue\Actions\SaveCategory;
use App\Domain\Catalogue\Actions\SaveDepartment;
use App\Domain\Catalogue\Queries\CategoryTree;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\TillData\Models\Category;
use App\Domain\TillData\Models\Department;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\SaveCatalogueGroupRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Departments and categories of the tenant portal (module 4.2): the tree (`catalogue.view`) and its edits
 * (`catalogue.manage`). Hub-owned rows: every till receives each change at its next pull.
 */
class CatalogueGroupController extends Controller
{
    public function index(CurrentCompany $tenancy): Response
    {
        return Inertia::render('app/products/categories', [...CategoryTree::get(), 'canManage' => $tenancy->can(Ability::CatalogueManage)]);
    }

    public function storeDepartment(SaveCatalogueGroupRequest $request, SaveDepartment $save): RedirectResponse
    {
        $department = $save->handle(null, $request->attributesToSave());

        return back()->with('success', "Department {$department->name} added.");
    }

    public function updateDepartment(SaveCatalogueGroupRequest $request, string $department, SaveDepartment $save): RedirectResponse
    {
        $model = $save->handle(Department::query()->findOrFail($department), $request->attributesToSave());

        return back()->with('success', "Department {$model->name} saved.");
    }

    public function destroyDepartment(string $department, DeleteCatalogueGroup $delete): RedirectResponse
    {
        return $this->delete(Department::query()->findOrFail($department), $delete);
    }

    public function storeCategory(SaveCatalogueGroupRequest $request, SaveCategory $save): RedirectResponse
    {
        $category = $save->handle(null, $request->attributesToSave());

        return back()->with('success', "Category {$category->name} added.");
    }

    public function updateCategory(SaveCatalogueGroupRequest $request, string $category, SaveCategory $save): RedirectResponse
    {
        $model = $save->handle(Category::query()->findOrFail($category), $request->attributesToSave());

        return back()->with('success', "Category {$model->name} saved.");
    }

    public function destroyCategory(string $category, DeleteCatalogueGroup $delete): RedirectResponse
    {
        return $this->delete(Category::query()->findOrFail($category), $delete);
    }

    private function delete(Department|Category $group, DeleteCatalogueGroup $delete): RedirectResponse
    {
        try {
            $delete->handle($group);
        } catch (ValidationException $e) {
            return back()->with('error', (string) collect($e->errors())->flatten()->first());
        }

        return back()->with('success', "{$group->name} deleted.");
    }
}
