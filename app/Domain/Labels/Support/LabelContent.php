<?php

namespace App\Domain\Labels\Support;

use App\Domain\Labels\Models\LabelQueueItem;
use App\Domain\Pricing\Support\ShopPrices;
use App\Domain\Promotions\Support\PromotionSummary;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\MoneyFormat;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductBarcode;
use App\Domain\TillData\Models\PromotionItem;
use App\Domain\TillData\Models\PromotionRule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What each shelf label says (gap #6), the same for the preview and the PDF: the price the shop charges when the label
 * goes up (its own live base price, else the business price; a scheduled change: from its `due_at`), the UK unit
 * price, the primary barcode, the best live offer at that shop ("3 for £2.00"), the PMP and deposit, shop and date.
 */
final class LabelContent
{
    /**
     * @param  Collection<int, LabelQueueItem>  $items  of one company (products and branches loaded here)
     * @return list<array<string, mixed>>
     */
    public static function for(Collection $items): array
    {
        if ($items->isEmpty()) {
            return [];
        }

        $companyId = $items->first()->company_id;
        $products = Product::withoutCompanyScope()->where('company_id', $companyId)->whereIn('id', $items->pluck('product_id')->unique()->all())->get()->keyBy('id');
        $shops = Branch::withoutCompanyScope()->where('company_id', $companyId)->whereIn('id', $items->pluck('branch_id')->unique()->all())->pluck('name', 'id');
        $barcodes = self::barcodes($companyId, $products->keys()->all());
        [$rules, $ruleItems] = self::offers($companyId);
        $now = CarbonImmutable::now('UTC');
        $liveNow = ShopPrices::live($products->keys()->all(), $shops->keys()->all(), $now);
        $labels = [];

        foreach ($items as $item) {
            $product = $products->get($item->product_id);
            if (! $product instanceof Product) {
                continue;
            }

            $later = $item->due_at !== null && $item->due_at->greaterThan($now);
            $at = $later ? $item->due_at : $now;
            $own = ($later ? ShopPrices::live([$product->id], [$item->branch_id], $at) : $liveNow)[$product->id][$item->branch_id][''] ?? null;
            $price = (string) ($own->price ?? $product->sell_price);
            $weighed = $product->is_weighed || $product->unit_type?->value === 'kg';
            $offer = self::offer($rules, $ruleItems, $product, $item->branch_id, $at->setTimezone(Country::zone())->toDateString());

            $labels[] = [
                'id' => $item->id,
                'productId' => $product->id,
                'name' => (string) $product->name,
                'price' => $price,
                'priceText' => MoneyFormat::format($price, ukStyle: MoneyFormat::AS_GIVEN).($weighed ? '/kg' : ''),
                'unitPrice' => $weighed ? null : (UnitPrice::for($product, $price)['text'] ?? null),
                'barcode' => Barcode::for($barcodes[$product->id] ?? $product->sku),
                'offer' => $offer === null ? null : PromotionSummary::deal($offer),
                'offerUntil' => $offer?->effective_to === null ? null : 'Ends '.$offer->effective_to->format('j M'),
                'pmp' => $product->pmp_price !== null && bccomp((string) $product->pmp_price, '0', 2) > 0 ? 'PMP '.MoneyFormat::format($product->pmp_price, ukStyle: MoneyFormat::AS_GIVEN) : null,
                'deposit' => $product->is_deposit_item && $product->deposit_amount !== null && bccomp((string) $product->deposit_amount, '0', 2) > 0
                    ? '+ '.UnitPrice::money((string) $product->deposit_amount).' deposit' : null,
                'shop' => (string) ($shops[$item->branch_id] ?? ''),
                'date' => $at->setTimezone(Country::zone())->format('d/m/y'),
                'copies' => max(1, $item->copies),
            ];
        }

        return $labels;
    }

    /**
     * The primary (else first) barcode of each product.
     *
     * @param  list<string>  $productIds
     * @return array<string, string>
     */
    private static function barcodes(string $companyId, array $productIds): array
    {
        $out = [];
        ProductBarcode::withoutCompanyScope()->where('company_id', $companyId)->whereIn('product_id', $productIds)
            ->orderByDesc('is_primary')->orderBy('id')->get(['product_id', 'barcode'])
            ->each(function (ProductBarcode $b) use (&$out) {
                $out[$b->product_id] ??= (string) $b->barcode;
            });

        return $out;
    }

    /**
     * Active offers without a coupon (the ones a shelf can show), highest priority first, and their items.
     *
     * @return array{0: Collection<int, PromotionRule>, 1: array<string, list<PromotionItem>>}
     */
    private static function offers(string $companyId): array
    {
        $rules = PromotionRule::withoutCompanyScope()->where('company_id', $companyId)->where('is_active', true)->where('requires_coupon', false)
            ->whereNotIn('scope', ['basket', 'customerGroup', 'branch'])->orderByDesc('priority')->orderBy('id')->get();
        $items = PromotionItem::withoutCompanyScope()->where('company_id', $companyId)->whereIn('promotion_rule_id', $rules->pluck('id')->all())->get()
            ->groupBy('promotion_rule_id')->map(fn ($group) => $group->values()->all())->all();

        return [$rules, $items];
    }

    /**
     * @param  Collection<int, PromotionRule>  $rules
     * @param  array<string, list<PromotionItem>>  $items
     */
    private static function offer(Collection $rules, array $items, Product $product, string $branchId, string $day): ?PromotionRule
    {
        return $rules->first(fn (PromotionRule $r) => ($r->branch_id === null || $r->branch_id === $branchId)
            && $r->effective_from->toDateString() <= $day && ($r->effective_to === null || $r->effective_to->toDateString() >= $day)
            && PromotionProducts::covers($r, $product, $items));
    }
}
