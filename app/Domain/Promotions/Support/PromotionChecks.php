<?php

namespace App\Domain\Promotions\Support;

use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\Category;
use App\Domain\TillData\Models\Department;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\PromotionRule;
use Illuminate\Validation\ValidationException;

/**
 * The business checks of an offer (module 4.3) that the form cannot make: the type is one the portal makes (or the
 * rule already had it), the values that type needs, the target and items exist in this business, the shop is this
 * business's, and the dates and times make sense. Throws a ValidationException keyed as the form names fields.
 */
final class PromotionChecks
{
    /**
     * @param  array<string, mixed>  $data  normalised by SavePromotion
     * @param  list<array{scope: string, target_id: string, group_no?: int|string|null}>  $items
     */
    public function check(?PromotionRule $rule, array $data, array $items): void
    {
        $type = (string) $data['type'];
        $errors = [];

        if (! in_array($type, PromotionTypes::PORTAL_TYPES, true) && $rule?->type?->value !== $type) {
            $errors['type'] = 'This kind of offer is set up on a till.';
        }

        $errors += $this->values($type, $data);

        if (in_array($type, PromotionTypes::ITEM_TYPES, true)) {
            $errors += $this->items($type, $items);
        } else {
            $errors += $this->target($rule, (string) $data['scope'], (string) $data['target_id']);
        }

        if ($data['branch_id'] !== null && ! Branch::query()->whereKey($data['branch_id'])->exists()) {
            $errors['branch_id'] = 'Choose a shop of this business.';
        }

        if ($data['effective_to'] !== null && $data['effective_to'] < $data['effective_from']) {
            $errors['effective_to'] = 'The offer must end on or after the day it starts.';
        }

        if (($data['time_from'] === null) !== ($data['time_to'] === null)) {
            $errors['time_to'] = 'Give both times, or neither for all day.';
        } elseif ($data['time_from'] !== null && $data['time_to'] <= $data['time_from']) {
            $errors['time_to'] = 'The end time must be after the start time.';
        }

        if ($data['requires_coupon'] && $data['coupon_code'] === '') {
            $errors['coupon_code'] = 'Enter the coupon code customers give.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function values(string $type, array $data): array
    {
        $uses = PromotionTypes::USES[$type] ?? [];
        $errors = [];

        if (in_array('percent', $uses, true) && ((float) $data['percent'] <= 0 || (float) $data['percent'] > 100)) {
            $errors['percent'] = 'Enter a percentage from 0.01 to 100.';
        }
        if (in_array('amount_off', $uses, true) && (float) $data['amount_off'] <= 0) {
            $errors['amount_off'] = 'Enter how much comes off, e.g. 0.50.';
        }
        if (in_array('deal_price', $uses, true) && (float) $data['deal_price'] <= 0) {
            $errors['deal_price'] = 'Enter the offer price, e.g. 2.00.';
        }
        if (in_array('buy_quantity', $uses, true) && $data['buy_quantity'] < ($type === 'multiBuy' || $type === 'mixMatch' ? 2 : 1)) {
            $errors['buy_quantity'] = $type === 'multiBuy' || $type === 'mixMatch' ? 'A multi-buy is for 2 or more items.' : 'Enter how many to buy.';
        }
        if (in_array('get_quantity', $uses, true) && $data['get_quantity'] < 1) {
            $errors['get_quantity'] = 'Enter how many the customer gets.';
        }

        return $errors;
    }

    /**
     * @return array<string, string>
     */
    private function target(?PromotionRule $rule, string $scope, string $targetId): array
    {
        if ($rule !== null && $rule->scope?->value === $scope && $rule->target_id === $targetId) {
            return []; // Unchanged (a till may use a scope the portal does not offer).
        }

        if (! in_array($scope, PromotionTypes::TARGET_SCOPES, true)) {
            return ['scope' => 'Choose a product, category, department or the whole basket.'];
        }

        return $scope === 'basket' || $this->exists($scope, $targetId) ? [] : ['target_id' => 'Choose the '.$scope.' this offer is on.'];
    }

    /**
     * @param  list<array{scope: string, target_id: string, group_no?: int|string|null}>  $items
     * @return array<string, string>
     */
    private function items(string $type, array $items): array
    {
        if ($items === []) {
            return ['items' => 'Add the items in this offer.'];
        }

        foreach ($items as $i => $item) {
            if (! $this->exists($item['scope'], $item['target_id'])) {
                return ["items.{$i}.target_id" => 'Choose an item of this business.'];
            }
        }

        $groups = array_unique(array_map(fn (array $item) => (int) ($item['group_no'] ?? 1), $items));

        return $type === 'mealDeal' && count($groups) < 2 ? ['items' => 'A meal deal needs at least two groups (e.g. main, snack, drink).'] : [];
    }

    private function exists(string $scope, string $id): bool
    {
        return $id !== '' && match ($scope) {
            'product' => Product::query()->whereKey($id)->exists(),
            'category' => Category::query()->whereKey($id)->exists(),
            'department' => Department::query()->whereKey($id)->exists(),
            default => false,
        };
    }
}
