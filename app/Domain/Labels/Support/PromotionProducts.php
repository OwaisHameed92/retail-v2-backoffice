<?php

namespace App\Domain\Labels\Support;

use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\PromotionItem;
use App\Domain\TillData\Models\PromotionRule;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which products an offer is on, for shelf labels (gap #6): a product, a category (or sub-category), a department, a
 * style (a variant parent and its variants) or, for a mix-and-match / meal deal, its items less the excluded ones.
 * A basket offer is on no product label. Works for any company (explicit `company_id`, no current company needed).
 */
final class PromotionProducts
{
    /**
     * Active product ids the offer is on.
     *
     * @return list<string>
     */
    public static function ids(PromotionRule $rule): array
    {
        $scope = $rule->scope?->value;

        if ($scope === 'itemGroup') {
            $items = PromotionItem::withoutCompanyScope()->where('company_id', $rule->company_id)->where('promotion_rule_id', $rule->id)->get();
            $in = $items->where('is_excluded', false)->flatMap(fn (PromotionItem $i) => self::target($rule->company_id, $i->scope?->value, $i->target_id))->unique();
            $out = $items->where('is_excluded', true)->flatMap(fn (PromotionItem $i) => self::target($rule->company_id, $i->scope?->value, $i->target_id))->unique();

            return array_values($in->diff($out)->all());
        }

        return self::target($rule->company_id, $scope, $rule->target_id);
    }

    /**
     * Whether a live offer is on this product (same rules as ids(), without querying the catalogue).
     *
     * @param  array<string, list<PromotionItem>>  $itemsByRule
     */
    public static function covers(PromotionRule $rule, Product $product, array $itemsByRule): bool
    {
        $scope = $rule->scope?->value;

        if ($scope !== 'itemGroup') {
            return self::matches($scope, $rule->target_id, $product);
        }

        $items = $itemsByRule[$rule->id] ?? [];
        $in = array_filter($items, fn (PromotionItem $i) => ! $i->is_excluded && self::matches($i->scope?->value, $i->target_id, $product));
        $out = array_filter($items, fn (PromotionItem $i) => $i->is_excluded && self::matches($i->scope?->value, $i->target_id, $product));

        return $in !== [] && $out === [];
    }

    private static function matches(?string $scope, string $target, Product $product): bool
    {
        return match ($scope) {
            'product' => $product->id === $target,
            'category' => $product->category_id === $target || $product->sub_category_id === $target,
            'department' => $product->department_id === $target,
            'style' => $product->id === $target || $product->parent_product_id === $target,
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    private static function target(string $companyId, ?string $scope, string $target): array
    {
        if ($target === '' || ! in_array($scope, ['product', 'category', 'department', 'style'], true)) {
            return [];
        }

        return Product::withoutCompanyScope()->where('company_id', $companyId)->where('is_active', true)
            ->where(fn (Builder $q) => match ($scope) {
                'product' => $q->whereKey($target),
                'category' => $q->where('category_id', $target)->orWhere('sub_category_id', $target),
                'department' => $q->where('department_id', $target),
                default => $q->whereKey($target)->orWhere('parent_product_id', $target),
            })
            ->pluck('id')->map(fn ($id) => (string) $id)->all();
    }
}
