<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Data\CatalogueFilters;
use App\Domain\OnlineStore\Data\CatalogueItem;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Support\OnlineCatalogueQuery;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\OnlineStore\Support\StoreScope;
use App\Domain\Promotions\Queries\PromotionList;
use App\Domain\Promotions\Support\PromotionSummary;
use App\Domain\Promotions\Support\PromotionTypes;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\PromotionItem;
use App\Domain\TillData\Models\PromotionRule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The till offers a store can show (OS-8, read only on the till's promotion tables): live today at the shop (every
 * shop or this one), for everyone (no coupon, no customer group), all day every day (an offer limited to some days
 * or hours may not apply when the order is collected), on a product, a category, a department or chosen items. The
 * till takes the offer off when the order is rung up at collection; the store only shows "3 for £2.00" beside the
 * shelf price.
 *
 *     $offers->labels($store, $shop, $items);   // [productId => '3 for £2.00']
 *     $offers->products($store, $shop, 12);     // products on offer shown at the shop
 */
final class StoreOffers
{
    /** At most this many live offers are read per request. */
    private const MAX_RULES = 200;

    /** @var array<string, array{product: array<string, string>, category: array<string, string>, department: array<string, string>}> */
    private array $cache = [];

    /**
     * The offer label of each of these products that has one.
     *
     * @param  list<CatalogueItem>  $items
     * @return array<string, string>
     */
    public function labels(OnlineStore $store, OnlineStoreShop $shop, array $items): array
    {
        if ($items === []) {
            return [];
        }

        $targets = $this->targets($store, $shop);
        $out = [];

        foreach ($items as $item) {
            $label = $targets['product'][$item->id]
                ?? ($item->categoryId !== null ? $targets['category'][$item->categoryId] ?? null : null)
                ?? ($item->departmentId !== null ? $targets['department'][$item->departmentId] ?? null : null);

            if ($label !== null) {
                $out[$item->id] = $label;
            }
        }

        return $out;
    }

    /**
     * Up to `$limit` products on offer that the store shows at the shop and can be ordered now, by name.
     *
     * @return list<CatalogueItem>
     */
    public function products(OnlineStore $store, OnlineStoreShop $shop, int $limit): array
    {
        $targets = $this->targets($store, $shop);

        if ($targets['product'] === [] && $targets['category'] === [] && $targets['department'] === []) {
            return [];
        }

        return StoreScope::run($store, function () use ($shop, $targets, $limit): array {
            $query = OnlineCatalogueQuery::base($shop)->where(function (Builder $q) use ($targets) {
                $q->whereIn('products.id', array_keys($targets['product']) ?: [''])
                    ->orWhereIn('products.category_id', array_keys($targets['category']) ?: [''])
                    ->orWhereIn('products.department_id', array_keys($targets['department']) ?: ['']);
            });
            OnlineCatalogueQuery::filter($query, $shop, new CatalogueFilters(inStockOnly: true, homeRow: true));

            /** @var Collection<int, Product> $rows */
            $rows = OnlineCatalogueQuery::select($query, $shop)->orderBy('products.name')->orderBy('products.id')->limit($limit)->get();

            return $rows->map(fn (Product $row) => OnlineCatalogueQuery::item($row))->values()->all();
        });
    }

    /**
     * The live offers' labels by what they are on (a product's own offer wins over its category's, then department's).
     *
     * @return array{product: array<string, string>, category: array<string, string>, department: array<string, string>}
     */
    private function targets(OnlineStore $store, OnlineStoreShop $shop): array
    {
        $key = $store->id.'|'.$shop->branch_id;

        return $this->cache[$key] ??= StoreScope::run($store, function () use ($store, $shop): array {
            $today = CarbonImmutable::now(OnlineStoreSwitch::timezone())->toDateString();
            $rules = PromotionList::status(PromotionRule::query(), 'live', $today)
                ->where('company_id', $store->company_id)
                ->where(fn (Builder $q) => $q->whereNull('branch_id')->orWhere('branch_id', $shop->branch_id))
                ->where(fn (Builder $q) => $q->whereNull('requires_coupon')->orWhere('requires_coupon', false))
                ->whereNull('customer_group_id')->whereNull('time_from')->whereNull('time_to')
                ->whereIn('scope', ['product', 'category', 'department', 'itemGroup'])
                ->orderByDesc('priority')->orderBy('id')->limit(self::MAX_RULES)->get()
                ->filter(fn (PromotionRule $r) => count(PromotionTypes::dayList($r->days)) === 7);

            $out = ['product' => [], 'category' => [], 'department' => []];
            $groups = $rules->filter(fn (PromotionRule $r) => $r->scope?->value === 'itemGroup');
            $items = $groups->isEmpty() ? collect() : PromotionItem::query()->where('company_id', $store->company_id)
                ->whereIn('promotion_rule_id', $groups->pluck('id')->all())
                ->where(fn (Builder $q) => $q->whereNull('is_excluded')->orWhere('is_excluded', false))->get(['promotion_rule_id', 'scope', 'target_id']);

            foreach ($rules as $rule) {
                $label = PromotionSummary::deal($rule);
                $scope = (string) $rule->scope?->value;

                if ($scope === 'itemGroup') {
                    foreach ($items->where('promotion_rule_id', $rule->id) as $item) {
                        $itemScope = (string) $item->scope?->value;

                        if (isset($out[$itemScope]) && $item->target_id !== null) {
                            $out[$itemScope][(string) $item->target_id] ??= $label;
                        }
                    }
                } elseif (isset($out[$scope]) && $rule->target_id !== '') {
                    $out[$scope][(string) $rule->target_id] ??= $label;
                }
            }

            return $out;
        });
    }
}
