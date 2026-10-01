<?php

namespace App\Domain\Promotions\Support;

use App\Domain\TillData\Enums\PromotionScope;
use App\Domain\TillData\Enums\PromotionType;

/**
 * What each offer type needs (module 4.3, ANSWERS-2026-10-01 §2). Every type can be made on the portal: `multiBuy` (one
 * tier: buyQuantity for dealPrice), `quantityPrice` (several tiers in `priceTiers`, PriceTiers), `mixMatch` (items of
 * any product/category/department/style, groupNo 0) and `mealDeal` (one item from each group 1..n).
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
    public const PORTAL_TYPES = ['percentOff', 'fixedOff', 'fixedPrice', 'multiBuy', 'quantityPrice', 'bogof', 'buyGet', 'mixMatch', 'mealDeal'];

    /** What a PromotionItem may be (a style is a variant parent product: every size or colour of it). */
    public const ITEM_SCOPES = ['product', 'category', 'department', 'style'];

    /** The till's `DaysOfWeek` flags, in its order ("monday, tuesday, …"; all seven = "all"). */
    public const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /** What a single-target offer may be on (the item types use `itemGroup`; `quantityPrice` is on one product). */
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

    /**
     * The days as the till's flags string: the chosen days in the till's order, comma + space; none or all = "all".
     * Takes a list of day names or the till's string ("saturday, sunday", "all", "none", "" = every day).
     *
     * @param  list<string>|string|null  $days
     */
    public static function days(array|string|null $days): string
    {
        $chosen = is_array($days) ? $days : preg_split('/\s*,\s*/', strtolower(trim((string) $days)), -1, PREG_SPLIT_NO_EMPTY);
        $chosen = array_values(array_intersect(self::DAYS, array_map('strtolower', (array) $chosen)));

        return $chosen === [] || count($chosen) === 7 ? 'all' : implode(', ', $chosen);
    }

    /**
     * The days of a stored flags string as a list (every day for "all", "none" or blank).
     *
     * @return list<string>
     */
    public static function dayList(?string $days): array
    {
        $flags = preg_split('/\s*,\s*/', strtolower(trim((string) $days)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $list = array_values(array_intersect(self::DAYS, $flags));

        return $list === [] ? self::DAYS : $list;
    }
}
