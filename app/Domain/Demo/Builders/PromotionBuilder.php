<?php

namespace App\Domain\Demo\Builders;

use App\Domain\Demo\Support\DemoBusiness;
use App\Domain\Demo\Support\DemoPush;
use App\Domain\Reporting\Demo\DemoCatalogue;
use App\Domain\Reporting\Demo\DemoShop;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The offers: the multi-buys the demo sales apply (same ids, so sale lines point at them, with their redemption
 * count), price-by-quantity tiers on lager, a lunchtime meal deal (sandwich + snack + drink), an after-school
 * happy hour on fresh bakery, a monster energy multi-buy for one shop only and an expired Easter offer. The last two
 * weeks of multi-buy lines are written as promotion redemptions.
 */
final class PromotionBuilder
{
    public const REDEMPTION_DAYS = 14;

    public function handle(DemoBusiness $b, DemoPush $push): void
    {
        $at = $b->at($b->history + 10, 9);
        $counts = DB::table('sale_lines')->where('company_id', $b->companyId)->whereNotNull('promotion_id')
            ->groupBy('promotion_id')->pluck(DB::raw('COUNT(*)'), 'promotion_id')->all();

        foreach (DemoCatalogue::MULTIBUYS as $key => [$qty, $price, $name]) {
            $id = $b->id("promotion|{$key}");
            $this->rule($b, $push, $id, $name, 'multiBuy', 'product', $b->id("product|{$key}"), $at, [
                'buyQuantity' => $qty, 'dealPrice' => $price / 100, 'redemptionCount' => (int) ($counts[$id] ?? 0),
            ]);
        }

        $this->rule($b, $push, $b->id('promotion|fosters-tiers'), 'Fosters 4 pack: 2 for £10, 3 for £14', 'quantityPrice', 'product', $b->id('product|fosters-lager-4-x-440ml'), $at, [
            'priceTiers' => '2=10.00;3=14.00',
        ]);
        $this->rule($b, $push, $b->id('promotion|pepsi-tiers'), 'Pepsi Max 2L: 2 for £4, 3 for £5.50', 'quantityPrice', 'product', $b->id('product|pepsi-max-2l'), $at, [
            'priceTiers' => '2=4.00;3=5.50',
        ]);

        $deal = $b->id('promotion|meal-deal');
        $this->rule($b, $push, $deal, 'Lunch meal deal £3.99', 'mealDeal', 'itemGroup', '', $at, ['dealPrice' => 3.99, 'isGroupOffer' => true, 'isHfssSafe' => false]);

        foreach ([1 => 'food2go', 2 => 'crisps', 3 => 'softdrinks'] as $group => $category) {
            $push->add($b->main(), 'PromotionItem', $b->id("promotion|meal-deal|{$group}"), [
                'promotionRuleId' => $deal, 'scope' => 'category', 'targetId' => $b->id("category|{$category}"), 'groupNo' => $group, 'quantity' => 1,
                'isExcluded' => false, 'attributeFilter' => null,
            ], $at);
        }

        $this->rule($b, $push, $b->id('promotion|bakery-happy-hour'), 'Bakery happy hour 4–6pm: 25% off', 'percentOff', 'category', $b->id('category|bakery'), $at, [
            'percent' => 25, 'timeFrom' => '16:00:00', 'timeTo' => '18:00:00', 'days' => 'monday, tuesday, wednesday, thursday, friday',
        ]);

        $second = $b->shops[1]['shop'] ?? null;
        $this->rule($b, $push, $b->id('promotion|monster-2-for-3'), 'Monster 2 for £3'.($second !== null ? ' ('.$second->branchCode.' only)' : ''), 'multiBuy', 'product', $b->id('product|monster-energy-green-500ml'), $at, [
            'buyQuantity' => 2, 'dealPrice' => 3.00, 'branchId' => $second?->branchId,
        ]);
        $this->rule($b, $push, $b->id('promotion|easter-galaxy'), 'Easter: Galaxy 3 for £2.50', 'multiBuy', 'product', $b->id('product|galaxy-smooth-milk-42g'), $at, [
            'buyQuantity' => 3, 'dealPrice' => 2.50, 'effectiveFrom' => '2026-03-23', 'effectiveTo' => '2026-04-12',
        ]);

        $this->redemptions($b, $push);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function rule(DemoBusiness $b, DemoPush $push, string $id, string $name, string $type, string $scope, string $target, CarbonImmutable $at, array $fields): void
    {
        $push->add($b->main(), 'PromotionRule', $id, [
            'name' => $name, 'type' => $type, 'scope' => $scope, 'targetId' => $target, 'percent' => 0, 'amountOff' => 0, 'dealPrice' => 0,
            'memberValue' => 0, 'buyQuantity' => 0, 'getQuantity' => 0, 'priceTiers' => null, 'minQuantity' => 0, 'isGroupOffer' => false,
            'priority' => 10, 'allowStack' => false, 'isExclusive' => false, 'maxRedemptionsPerSale' => null, 'maxRedemptionsTotal' => null,
            'redemptionCount' => 0, 'couponCode' => '', 'requiresCoupon' => false, 'branchId' => null, 'customerGroupId' => null, 'isHfssSafe' => true,
            'effectiveFrom' => $b->date($b->history + 10), 'effectiveTo' => null, 'seasonalEventId' => '', 'days' => 'all', 'timeFrom' => null,
            'timeTo' => null, 'isActive' => true, ...$fields,
        ], $at);
    }

    private function redemptions(DemoBusiness $b, DemoPush $push): void
    {
        $shops = [];

        foreach ($b->shops as ['shop' => $shop]) {
            $shops[$shop->branchId] = $shop;
        }

        $lines = DB::table('sale_lines as l')->join('sales as s', fn ($j) => $j->on('s.id', '=', 'l.sale_id')->on('s.company_id', '=', 'l.company_id'))
            ->where('s.company_id', $b->companyId)->where('s.status', 'completed')->where('s.type', 'sale')->whereNotNull('l.promotion_id')
            ->where('s.trading_day', '>=', $b->date(self::REDEMPTION_DAYS - 1))
            ->get(['l.id', 'l.sale_id', 'l.promotion_id', 'l.promotion_name', 'l.promotion_discount', 's.user_id', 's.register_id', 's.branch_id', 's.trading_day', 's.completed_at']);

        foreach ($lines as $l) {
            $shop = $shops[(string) $l->branch_id] ?? null;

            if (! $shop instanceof DemoShop) {
                continue;
            }

            $at = CarbonImmutable::parse((string) $l->completed_at, 'UTC');
            $push->add($shop, 'PromotionRedemption', $shop->id("redemption|{$l->id}"), [
                'saleId' => (string) $l->sale_id, 'saleLineId' => (string) $l->id, 'promotionRuleId' => (string) $l->promotion_id,
                'promotionName' => (string) $l->promotion_name, 'type' => 'multiBuy', 'scope' => 'product', 'source' => 'promotion',
                'amountOff' => (float) $l->promotion_discount, 'couponCode' => '', 'userId' => (string) $l->user_id, 'registerId' => (string) $l->register_id,
                'branchId' => $shop->branchId, 'tradingDate' => substr((string) $l->trading_day, 0, 10),
            ], $at);
        }
    }
}
