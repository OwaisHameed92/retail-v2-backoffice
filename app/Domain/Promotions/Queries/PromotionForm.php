<?php

namespace App\Domain\Promotions\Queries;

use App\Domain\Promotions\Support\PriceTiers;
use App\Domain\Promotions\Support\PromotionSummary;
use App\Domain\Promotions\Support\PromotionTypes;
use App\Domain\Shared\Country\Country;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Enums\PromotionType;
use App\Domain\TillData\Models\Category;
use App\Domain\TillData\Models\Department;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\PromotionItem;
use App\Domain\TillData\Models\PromotionRule;
use Carbon\CarbonImmutable;

/**
 * Props for the offer form (module 4.3): the rule's values as strings (so nothing is rounded in the browser), its
 * items, price tiers as rows, days as a list, and the choices (types, products, categories, departments, styles, shops). A one-shop user may pick only their shop.
 */
final class PromotionForm
{
    /**
     * @return array<string, mixed>
     */
    public static function for(?PromotionRule $rule): array
    {
        $tenancy = app(CurrentCompany::class);
        $restricted = $tenancy->restrictedBranchId();
        $today = CarbonImmutable::now(Country::zone())->toDateString();
        $items = $rule === null ? collect() : PromotionItem::query()->where('promotion_rule_id', $rule->id)->orderBy('group_no')->orderBy('created_at')->get();
        $num = fn (string $v) => rtrim(rtrim($v, '0'), '.') ?: '0';

        return [
            'promotion' => $rule === null ? null : [
                'id' => $rule->id, 'name' => (string) $rule->name, 'type' => $rule->type->value ?? 'percentOff', 'scope' => $rule->scope->value ?? 'product',
                'target_id' => $rule->target_id, 'percent' => $num((string) $rule->percent), 'amount_off' => (string) $rule->amount_off,
                'deal_price' => (string) $rule->deal_price, 'buy_quantity' => (string) $rule->buy_quantity, 'get_quantity' => (string) $rule->get_quantity,
                'min_quantity' => (string) $rule->min_quantity, 'priority' => (string) $rule->priority, 'allow_stack' => $rule->allow_stack,
                'is_exclusive' => $rule->is_exclusive, 'max_redemptions_per_sale' => $rule->max_redemptions_per_sale === null ? '' : (string) $rule->max_redemptions_per_sale,
                'max_redemptions_total' => $rule->max_redemptions_total === null ? '' : (string) $rule->max_redemptions_total,
                'requires_coupon' => $rule->requires_coupon, 'coupon_code' => $rule->coupon_code, 'branch_id' => $rule->branch_id ?? '',
                'is_hfss_safe' => $rule->is_hfss_safe, 'effective_from' => $rule->effective_from->toDateString(),
                'effective_to' => $rule->effective_to?->toDateString() ?? '', 'time_from' => $rule->time_from === null ? '' : substr($rule->time_from, 0, 5),
                'time_to' => $rule->time_to === null ? '' : substr($rule->time_to, 0, 5), 'is_active' => $rule->is_active,
                'price_tiers' => PriceTiers::rows($rule->price_tiers), 'days' => PromotionTypes::dayList($rule->days),
                'items' => $items->map(fn (PromotionItem $i) => [
                    'id' => $i->id, 'scope' => $i->scope->value ?? 'product', 'target_id' => $i->target_id, 'group_no' => (string) $i->group_no,
                    'quantity' => (string) $i->quantity, 'is_excluded' => $i->is_excluded,
                ])->values()->all(),
                'status' => PromotionSummary::status($rule, $today), 'deal' => PromotionSummary::deal($rule), 'isGroupOffer' => $rule->is_group_offer,
                'redemptions' => $rule->redemption_count, 'updatedAt' => $rule->updated_at?->toIso8601ZuluString(),
            ],
            'options' => [
                // Every type in the till's order of PORTAL_TYPES (ANSWERS-2026-10-01 §2: all are made on the portal).
                'types' => array_map(fn (string $t) => ['value' => $t, 'label' => PromotionTypes::label(PromotionType::from($t))], PromotionTypes::PORTAL_TYPES),
                'products' => Product::query()->where('is_active', true)->orderBy('name')->limit(2000)->get(['id', 'name', 'sku'])
                    ->map(fn (Product $p) => ['value' => $p->id, 'label' => (string) $p->name.($p->sku ? " · {$p->sku}" : '')])->all(),
                'categories' => Category::query()->orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['value' => $c->id, 'label' => (string) $c->name])->all(),
                'departments' => Department::query()->orderBy('name')->get(['id', 'name'])->map(fn ($d) => ['value' => $d->id, 'label' => (string) $d->name])->all(),
                'styles' => Product::query()->where('is_variant_parent', true)->orderBy('name')->limit(2000)->get(['id', 'name'])
                    ->map(fn (Product $p) => ['value' => $p->id, 'label' => (string) $p->name])->all(),
                'shops' => Branch::query()->when($restricted !== null, fn ($q) => $q->whereKey($restricted), fn ($q) => $q->where('is_active', true))
                    ->orderBy('name')->get(['id', 'name'])->map(fn ($b) => ['value' => $b->id, 'label' => $b->name])->all(),
            ],
            'restrictedShop' => $restricted,
            'canEdit' => $tenancy->can(Ability::PromotionsManage) && ($restricted === null || $rule === null || $rule->branch_id === $restricted),
            'today' => $today,
        ];
    }
}
