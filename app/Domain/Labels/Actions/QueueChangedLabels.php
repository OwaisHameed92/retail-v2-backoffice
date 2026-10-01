<?php

namespace App\Domain\Labels\Actions;

use App\Domain\Labels\Enums\LabelReason;
use App\Domain\Labels\Support\PromotionProducts;
use App\Domain\Promotions\Support\PromotionSummary;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\BranchPrice;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\PromotionRule;

/**
 * The automatic side of the shelf-label queue (gap #6), called by the price and offer actions of modules 4.2 / 4.3:
 * a business price change (SaveProduct, "every shop"), a shop price set or ended (SetShopPrice, EndShopPrice), an
 * offer saved or ended while live (SavePromotion, EndPromotion). Offers that start or end on a later day are queued
 * on that day by `labels:queue-offers`.
 */
final class QueueChangedLabels
{
    public function __construct(private readonly QueueLabels $queue) {}

    /** Every open shop selling at the business price (shops with their own live price are left out). */
    public function businessPrice(Product $product, string $before, string $after): void
    {
        if (bccomp($before, $after, 2) === 0) {
            return;
        }

        $this->queue->handle($product->company_id, [$product->id], null, LabelReason::PriceChange, '£'.$before.' → £'.$after, skipOwnPrice: true);
    }

    /** A shop's own price for the product (base unit only; a pack price has no shelf label). */
    public function shopPrice(Branch $branch, Product $product, BranchPrice $row): void
    {
        if ($row->product_unit_id !== null) {
            return;
        }

        $this->queue->handle($product->company_id, [$product->id], [$branch->id], LabelReason::ShopPrice, 'Shop price £'.$row->price, $row->valid_from_utc);
    }

    public function shopPriceEnded(Branch $branch, Product $product): void
    {
        $this->queue->handle($product->company_id, [$product->id], [$branch->id], LabelReason::ShopPriceEnded, 'Back to £'.$product->sell_price);
    }

    /**
     * What an offer was on before an edit, when it was live (null = not live: nothing on the shelf to change).
     *
     * @return array{products: list<string>, branch: string|null}|null
     */
    public function snapshot(?PromotionRule $rule): ?array
    {
        if ($rule === null || ! $rule->exists || PromotionSummary::status($rule, PromotionSummary::today()) !== 'live') {
            return null;
        }

        return ['products' => PromotionProducts::ids($rule), 'branch' => $rule->branch_id];
    }

    /**
     * An offer saved or ended: its products now (when live) and before (when it was live) need new labels.
     *
     * @param  array{products: list<string>, branch: string|null}|null  $before
     */
    public function offer(PromotionRule $rule, ?array $before): void
    {
        $live = PromotionSummary::status($rule, PromotionSummary::today()) === 'live';
        $reason = $live ? LabelReason::PromotionStarted : LabelReason::PromotionEnded;
        $detail = mb_substr((string) $rule->name, 0, 120).' · '.PromotionSummary::deal($rule);

        if ($before !== null && (! $live || $before['branch'] !== $rule->branch_id)) {
            $this->queue->handle($rule->company_id, $before['products'], $before['branch'] === null ? null : [$before['branch']], LabelReason::PromotionEnded, $detail);
        }

        if ($live) {
            $now = PromotionProducts::ids($rule);
            $products = $before !== null && $before['branch'] === $rule->branch_id ? array_values(array_unique([...$now, ...$before['products']])) : $now;
            $this->queue->handle($rule->company_id, $products, $rule->branch_id === null ? null : [$rule->branch_id], $reason, $detail);
        }
    }
}
