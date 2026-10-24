<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Data\CatalogueFilters;
use App\Domain\OnlineStore\Data\CatalogueItem;
use App\Domain\OnlineStore\Enums\FeaturedMode;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Support\HomeRowRules;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\OnlineStore\Support\StoreScope;
use App\Domain\Reporting\ReportTables;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The home page's featured products (OS-8), by the store's `featured_mode`:
 *
 * - Best sellers (the default): what sold most (quantity sold less returned, `rpt_product_daily`) in the last
 *   {@see self::DAYS} days to yesterday at the store's collection shops, only products the store shows at the
 *   visitor's shop and can be ordered now; the row is filled up with the newest products (all of it while there are
 *   no sales yet).
 * - Hand-picked: the products the business chose, in its order (up to {@see self::MAX_PICKED}), those shown at the shop.
 * - Newest: the products most recently added on the tills, orderable now.
 *
 * OS-11 (HomeRowRules): never an HFSS product (audit OSA-31); best sellers and newest also leave out tiny service
 * items (under £0.50, carrier bags, bag charges) and products the shop took out of "featured" on the Products tab.
 *
 * Read only; scoped to the store's business (StoreScope, explicit company filters).
 */
final class FeaturedProducts
{
    public const DAYS = 30;

    public const MAX_PICKED = 24;

    /** Best sellers read before the shown-online filter. */
    private const CANDIDATES = 120;

    /** @var array<string, list<string>> */
    private array $ranked = [];

    public function __construct(private readonly StorefrontCatalogue $catalogue) {}

    /**
     * The row's products and what they are: best sellers with no sales yet are the newest products.
     *
     * @param  list<string>  $branchIds  the store's collection shops (the best sellers' sales are theirs)
     * @return array{mode: FeaturedMode, items: list<CatalogueItem>}
     */
    public function handle(OnlineStore $store, OnlineStoreShop $shop, array $branchIds, int $limit): array
    {
        $mode = FeaturedMode::fromStored($store->featured_mode);

        if ($mode === FeaturedMode::BestSellers && $this->ranked($store, $branchIds) === []) {
            $mode = FeaturedMode::Newest;
        }

        return ['mode' => $mode, 'items' => match ($mode) {
            FeaturedMode::HandPicked => $this->picked($store, $shop),
            FeaturedMode::Newest => $this->newest($store, $shop, $limit),
            FeaturedMode::BestSellers => $this->bestSellers($store, $shop, $branchIds, $limit),
        }];
    }

    /**
     * @param  list<string>  $branchIds
     * @return list<CatalogueItem>
     */
    public function bestSellers(OnlineStore $store, OnlineStoreShop $shop, array $branchIds, int $limit): array
    {
        $ranked = StoreScope::run($store, fn (): array => HomeRowRules::keep($shop, $this->ranked($store, $branchIds), featurable: true));
        $items = $ranked === [] ? [] : $this->catalogue->items($store, $shop, $ranked);
        $out = [];

        foreach ($ranked as $id) {
            if (isset($items[$id]) && $items[$id]->orderable && count($out) < $limit) {
                $out[$id] = $items[$id];
            }
        }

        if (count($out) < $limit) {
            foreach ($this->newest($store, $shop, $limit + count($out)) as $item) {
                if (! isset($out[$item->id]) && count($out) < $limit) {
                    $out[$item->id] = $item;
                }
            }
        }

        return array_values($out);
    }

    /**
     * @return list<CatalogueItem>
     */
    public function newest(OnlineStore $store, OnlineStoreShop $shop, int $limit): array
    {
        return $this->catalogue->products($store, $shop, perPage: $limit,
            filters: new CatalogueFilters(sort: CatalogueFilters::SORT_NEWEST, inStockOnly: true, homeRow: true, featurable: true))->items;
    }

    /**
     * @return list<CatalogueItem>
     */
    public function picked(OnlineStore $store, OnlineStoreShop $shop): array
    {
        $ids = array_slice(self::pickedIds($store->featured_product_ids), 0, self::MAX_PICKED);
        // OS-11 / OSA-31: never an HFSS product on the home page, even hand-picked.
        $ids = StoreScope::run($store, fn (): array => HomeRowRules::keep($shop, $ids, featurable: false));
        $items = $ids === [] ? [] : $this->catalogue->items($store, $shop, $ids);

        return array_values(array_filter(array_map(fn (string $id) => $items[$id] ?? null, $ids)));
    }

    /**
     * The stored hand-picked ids, cleaned (strings, no repeats, in order).
     *
     * @return list<string>
     */
    public static function pickedIds(mixed $stored): array
    {
        $ids = array_filter(is_array($stored) ? $stored : [], fn ($id) => is_string($id) && $id !== '');

        return array_values(array_unique($ids));
    }

    /**
     * Product ids by quantity sold (less returns) at these shops in the window, most first.
     *
     * @param  list<string>  $branchIds
     * @return list<string>
     */
    private function ranked(OnlineStore $store, array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }

        $key = $store->id.'|'.implode(',', $branchIds);

        if (isset($this->ranked[$key])) {
            return $this->ranked[$key];
        }

        $yesterday = CarbonImmutable::now(OnlineStoreSwitch::timezone())->subDay();

        return $this->ranked[$key] = StoreScope::run($store, fn (): array => DB::table(ReportTables::PRODUCT_DAILY)
            ->where('company_id', $store->company_id)
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('trading_day', [$yesterday->subDays(self::DAYS - 1)->toDateString(), $yesterday->toDateString()])
            ->groupBy('product_id')
            ->havingRaw('SUM(qty) - SUM(refund_qty) > 0')
            ->orderByRaw('SUM(qty) - SUM(refund_qty) DESC')
            ->orderByRaw('SUM(net) DESC')
            ->orderBy('product_id')
            ->limit(self::CANDIDATES)
            ->pluck('product_id')
            ->map(fn ($id) => (string) $id)
            ->all());
    }
}
