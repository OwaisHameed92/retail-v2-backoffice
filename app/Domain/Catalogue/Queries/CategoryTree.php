<?php

namespace App\Domain\Catalogue\Queries;

use App\Domain\TillData\Models\Category;
use App\Domain\TillData\Models\Department;
use App\Domain\TillData\Models\Product;

/**
 * The departments and categories screen (module 4.2): every department with its categories and their sub-categories,
 * in till order, with product counts (two grouped queries on indexed columns).
 */
final class CategoryTree
{
    /**
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        $byDepartment = Product::query()->selectRaw('department_id, count(*) as n')->groupBy('department_id')->pluck('n', 'department_id')->all();
        $byCategory = Product::query()->selectRaw('category_id, count(*) as n')->groupBy('category_id')->pluck('n', 'category_id')->all();
        $bySub = Product::query()->whereNotNull('sub_category_id')->selectRaw('sub_category_id, count(*) as n')->groupBy('sub_category_id')->pluck('n', 'sub_category_id')->all();
        $categories = Category::query()->orderBy('position')->orderBy('name')->get();
        $children = $categories->whereNotNull('parent_category_id')->groupBy('parent_category_id');

        $node = fn (Category $c, array $counts) => [
            'id' => $c->id,
            'name' => (string) $c->name,
            'departmentId' => $c->department_id,
            'parentId' => $c->parent_category_id,
            'position' => $c->position,
            'colourHex' => $c->colour_hex,
            'isActive' => $c->is_active,
            'isVisibleOnTill' => $c->is_visible_on_till,
            'ageRuleDefault' => $c->age_rule_default->value ?? 'none',
            'defaultVatRateId' => $c->default_vat_rate_id,
            'negativeStockMode' => $c->negative_stock_mode?->value,
            'productCount' => (int) ($counts[$c->id] ?? 0),
        ];

        $departments = Department::query()->orderBy('position')->orderBy('name')->get()->map(fn (Department $d) => [
            'id' => $d->id,
            'name' => (string) $d->name,
            'position' => $d->position,
            'colourHex' => $d->colour_hex,
            'isActive' => $d->is_active,
            'isVisibleOnTill' => $d->is_visible_on_till,
            'showInReport' => $d->show_in_report,
            'defaultVatRateId' => $d->default_vat_rate_id,
            'productCount' => (int) ($byDepartment[$d->id] ?? 0),
            'categories' => $categories->whereNull('parent_category_id')->where('department_id', $d->id)->values()->map(fn (Category $c) => [
                ...$node($c, $byCategory),
                'children' => ($children->get($c->id) ?? collect())->values()->map(fn (Category $s) => $node($s, $bySub))->all(),
            ])->all(),
        ])->all();

        return ['departments' => $departments, 'options' => CatalogueOptions::all()];
    }
}
