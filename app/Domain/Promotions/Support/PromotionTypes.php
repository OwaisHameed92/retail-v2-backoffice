<?php

namespace App\Domain\Promotions\Support;

use App\Domain\TillData\Enums\PromotionScope;
use App\Domain\TillData\Enums\PromotionType;

/**
 * What each offer type needs (module 4.3). The portal makes the types whose members the contract makes plain; a
 * `quantityPrice` rule (its `priceTiers` format is the till's own) is shown and can be ended, but only a till creates it.
 * `isGroupOffer` is never set by a person: it is worked out from the rule, as the till does (PullPayload).
 */
final class PromotionTypes
{
    /** Numeric members each type uses; the others are saved as 0. */
    public const USES = [
        'percentOff' => ['percent'],
        'fixedOff' => ['amount_off'],
        'fixedPrice' => ['deal_price'],
        'multiBuy' => ['buy_quantity', 'deal_price'],
        'bogof' => ['buy_quantity', 'get_quantity'],
        'buyGet' => ['buy_quantity', 'get_quantity', 'percent'],
        'mixMatch' => ['buy_quantity', 'deal_price'],
        'mealDeal' => ['deal_price'],
        'quantityPrice' => [],
    ];

    public const NUMERIC = ['percent', 'amount_off', 'deal_price', 'buy_quantity', 'get_quantity'];

    /** Types built from a list of items (PromotionItem rows) instead of one target. */
    public const ITEM_TYPES = ['mixMatch', 'mealDeal'];

    /** Types the portal may create or switch a rule to. */
    public const PORTAL_TYPES = ['percentOff', 'fixedOff', 'fixedPrice', 'multiBuy', 'bogof', 'buyGet', 'mixMatch', 'mealDeal'];

    /** What a single-target offer may be on (the item types use `itemGroup`). */
    public const TARGET_SCOPES = ['product', 'category', 'department', 'basket'];

    public static function label(?PromotionType $type): string
    {
        return match ($type) {
            PromotionType::PercentOff => '% off',
            PromotionType::FixedOff => '£ off',
            PromotionType::FixedPrice => 'Fixed price',
            PromotionType::MultiBuy => 'Multi-buy',
            PromotionType::Bogof => 'Buy one get one free',
            PromotionType::BuyGet => 'Buy X get Y',
            PromotionType::MixMatch => 'Mix and match',
            PromotionType::MealDeal => 'Meal deal',
            PromotionType::QuantityPrice => 'Quantity price',
            null => 'Offer',
        };
    }

    public static function scopeLabel(?PromotionScope $scope): string
    {
        return match ($scope) {
            PromotionScope::Product => 'Product',
            PromotionScope::Category => 'Category',
            PromotionScope::Department => 'Department',
            PromotionScope::ItemGroup => 'Chosen items',
            PromotionScope::CustomerGroup => 'Customer group',
            PromotionScope::Branch => 'Whole shop',
            PromotionScope::Basket => 'Whole basket',
            PromotionScope::Style => 'Style',
            null => '—',
        };
    }

    /** As the till works it out (ANSWERS-2026-09-29-b): minQuantity ≥ 2, a % / £ off or fixed price, not the basket. */
    public static function isGroupOffer(string $type, string $scope, int $minQuantity): bool
    {
        return $minQuantity >= 2 && in_array($type, ['percentOff', 'fixedOff', 'fixedPrice'], true) && $scope !== 'basket';
    }
}
