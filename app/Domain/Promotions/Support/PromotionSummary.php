<?php

namespace App\Domain\Promotions\Support;

use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\MoneyFormat;
use App\Domain\TillData\Models\Category;
use App\Domain\TillData\Models\Department;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\PromotionRule;
use Carbon\CarbonImmutable;

/**
 * How an offer reads on the portal (module 4.3): "3 for £2.00 · Coke 500ml · All shops · live".
 */
final class PromotionSummary
{
    /**
     * Names of the products, categories and departments the rules are on, keyed "scope:id".
     *
     * @param  array<int, PromotionRule>  $rules
     * @return array<string, string>
     */
    public static function targetNames(array $rules): array
    {
        $ids = [];
        foreach ($rules as $rule) {
            $ids[$rule->scope->value ?? ''][] = $rule->target_id;
        }

        return self::names($ids);
    }

    /**
     * @param  array<string, list<string>>  $ids  scope => ids
     * @return array<string, string>
     */
    public static function names(array $ids): array
    {
        $names = [];
        foreach (['product' => Product::class, 'category' => Category::class, 'department' => Department::class] as $scope => $model) {
            if (($ids[$scope] ?? []) !== []) {
                foreach ($model::query()->whereIn('id', array_unique($ids[$scope]))->pluck('name', 'id') as $id => $name) {
                    $names["{$scope}:{$id}"] = (string) $name;
                }
            }
        }

        return $names;
    }

    /**
     * @param  array<string, string>  $names
     * @param  array<string, string>  $shops
     * @return array<string, mixed>
     */
    public static function row(PromotionRule $r, array $names, array $shops, string $today): array
    {
        $scope = $r->scope?->value;

        return [
            'id' => $r->id,
            'name' => (string) $r->name,
            'type' => $r->type?->value,
            'typeLabel' => PromotionTypes::label($r->type),
            'deal' => self::deal($r),
            'on' => match ($scope) {
                'basket', 'itemGroup', 'branch' => PromotionTypes::scopeLabel($r->scope),
                default => $names["{$scope}:{$r->target_id}"] ?? PromotionTypes::scopeLabel($r->scope),
            },
            'scopeLabel' => PromotionTypes::scopeLabel($r->scope),
            'branchId' => $r->branch_id,
            'shop' => $r->branch_id === null ? null : ($shops[$r->branch_id] ?? 'Another shop'),
            'from' => $r->effective_from->toDateString(),
            'to' => $r->effective_to?->toDateString(),
            'times' => self::when($r->days, $r->time_from, $r->time_to),
            'status' => self::status($r, $today),
            'couponCode' => $r->requires_coupon ? $r->coupon_code : null,
            'redemptions' => $r->redemption_count,
            'isGroupOffer' => $r->is_group_offer,
        ];
    }

    public static function status(PromotionRule $r, string $today): string
    {
        return match (true) {
            ! $r->is_active || ($r->effective_to !== null && $r->effective_to->toDateString() < $today) => 'ended',
            $r->effective_from->toDateString() > $today => 'scheduled',
            default => 'live',
        };
    }

    public static function deal(PromotionRule $r): string
    {
        $money = fn (string $v) => MoneyFormat::format(number_format((float) $v, 2, '.', ''), ukStyle: MoneyFormat::SIGN_AFTER_SYMBOL);
        $percent = rtrim(rtrim(number_format((float) $r->percent, 2, '.', ''), '0'), '.').'%';
        $min = $r->min_quantity >= 2 ? " when buying {$r->min_quantity}+" : '';

        return match ($r->type?->value) {
            'percentOff' => "{$percent} off{$min}",
            'fixedOff' => "{$money($r->amount_off)} off{$min}",
            'fixedPrice' => "{$money($r->deal_price)}".($r->min_quantity >= 2 ? " for {$r->min_quantity}" : ' each'),
            'multiBuy', 'mixMatch' => "{$r->buy_quantity} for {$money($r->deal_price)}",
            'bogof' => $r->buy_quantity <= 1 && $r->get_quantity <= 1 ? 'Buy one get one free' : "Buy {$r->buy_quantity} get {$r->get_quantity} free",
            'buyGet' => "Buy {$r->buy_quantity} get {$r->get_quantity} ".((float) $r->percent >= 100 ? 'free' : "{$percent} off"),
            'mealDeal' => "Meal deal {$money($r->deal_price)}",
            'quantityPrice' => PriceTiers::describe($r->price_tiers) ?: 'Price by quantity',
            default => 'Offer',
        };
    }

    /**
     * "Mon, Tue · 17:00–19:00", "Fri, Sat · 22:00–02:00 (past midnight)", or null for every day, all day.
     */
    public static function when(?string $days, ?string $from, ?string $to): ?string
    {
        $list = PromotionTypes::dayList($days);
        $parts = [];

        if (count($list) < 7) {
            $parts[] = implode(', ', array_map(fn (string $d) => ucfirst(substr($d, 0, 3)), $list));
        }

        if ($from !== null && $to !== null) {
            $parts[] = substr($from, 0, 5).'–'.substr($to, 0, 5).($to < $from ? ' (past midnight)' : '');
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /** London today, for status. */
    public static function today(): string
    {
        return CarbonImmutable::now(Country::zone())->toDateString();
    }
}
