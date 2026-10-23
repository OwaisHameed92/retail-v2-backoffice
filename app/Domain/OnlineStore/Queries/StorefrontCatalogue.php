<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Data\CatalogueFilters;
use App\Domain\OnlineStore\Data\CatalogueItem;
use App\Domain\OnlineStore\Data\CataloguePage;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Support\OnlineCatalogueQuery;
use App\Domain\OnlineStore\Support\StoreScope;
use App\Domain\TillData\Models\Department;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductBarcode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What a store's customers see at one collection shop (docs/online-store.md): departments with how many products
 * each shows, a product list (search, department, pages) and one product. The rules for what is shown are
 * OnlineCatalogueQuery's. Read only on the till's tables; scoped to the store's business (StoreScope) and refused
 * (empty) for a shop that is not this store's.
 */
final class StorefrontCatalogue
{
    public const PER_PAGE = 24;

    public const MAX_PER_PAGE = 60;

    /**
     * Departments with at least one product shown, in the till's order, with counts.
     *
     * @return list<array{id: string, name: string, count: int}>
     */
    public function departments(OnlineStore $store, OnlineStoreShop $shop): array
    {
        if (! self::belongs($store, $shop)) {
            return [];
        }

        return StoreScope::run($store, function () use ($store, $shop): array {
            $counts = OnlineCatalogueQuery::base($shop)->whereNotNull('products.department_id')
                ->groupBy('products.department_id')->selectRaw('products.department_id as department_id, COUNT(*) as aggregate')
                ->toBase()->pluck('aggregate', 'department_id');

            if ($counts->isEmpty()) {
                return [];
            }

            return Department::query()->where('company_id', $store->company_id)->whereIn('id', $counts->keys()->map(fn ($id) => (string) $id)->all())
                ->orderBy('position')->orderBy('name')->get(['id', 'name', 'position'])
                ->map(fn (Department $d) => ['id' => $d->id, 'name' => (string) $d->name, 'count' => (int) $counts[$d->id]])
                ->values()->all();
        });
    }

    /**
     * One page of products, by name: optionally a search (part of the name or brand, a code prefix or an exact
     * barcode) and a department. `$filters` (OS-2, the public store's lists): sort by price, in stock only, price range.
     */
    public function products(OnlineStore $store, OnlineStoreShop $shop, ?string $search = null, ?string $departmentId = null, int $page = 1, int $perPage = self::PER_PAGE, ?CatalogueFilters $filters = null): CataloguePage
    {
        $page = max(1, $page);
        $perPage = min(self::MAX_PER_PAGE, max(1, $perPage));
        $filters ??= new CatalogueFilters;

        if (! self::belongs($store, $shop)) {
            return new CataloguePage([], 0, $page, $perPage);
        }

        return StoreScope::run($store, function () use ($store, $shop, $search, $departmentId, $page, $perPage, $filters): CataloguePage {
            $query = OnlineCatalogueQuery::base($shop)
                ->when($departmentId !== null && $departmentId !== '', fn (Builder $q) => $q->where('products.department_id', $departmentId))
                ->when(self::term($search) !== null, fn (Builder $q) => self::search($q, (string) self::term($search), $store->company_id));
            OnlineCatalogueQuery::filter($query, $shop, $filters);
            $total = (clone $query)->count();

            $rows = OnlineCatalogueQuery::order(OnlineCatalogueQuery::select($query, $shop), $filters)
                ->forPage($page, $perPage)->get();

            return new CataloguePage($rows->map(fn (Product $row) => OnlineCatalogueQuery::item($row))->values()->all(), $total, $page, $perPage);
        });
    }

    /** One product as shown at this shop, or null when the store does not show it there. */
    public function product(OnlineStore $store, OnlineStoreShop $shop, string $productId): ?CatalogueItem
    {
        return $this->items($store, $shop, [$productId])[$productId] ?? null;
    }

    /**
     * Several products as shown at this shop, keyed by id; ids the store does not show are left out.
     *
     * @param  list<string>  $productIds
     * @return array<string, CatalogueItem>
     */
    public function items(OnlineStore $store, OnlineStoreShop $shop, array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter($productIds, fn (string $id) => $id !== '')));

        if ($productIds === [] || ! self::belongs($store, $shop)) {
            return [];
        }

        return StoreScope::run($store, function () use ($shop, $productIds): array {
            /** @var Collection<int, Product> $rows */
            $rows = OnlineCatalogueQuery::select(OnlineCatalogueQuery::base($shop)->whereIn('products.id', $productIds), $shop)->get();

            return $rows->mapWithKeys(fn (Product $row) => [$row->id => OnlineCatalogueQuery::item($row)])->all();
        });
    }

    /** The shop is one of this store's (same business, same store). */
    private static function belongs(OnlineStore $store, OnlineStoreShop $shop): bool
    {
        return $shop->online_store_id === $store->id && $shop->company_id === $store->company_id;
    }

    private static function term(?string $search): ?string
    {
        $term = trim(str_replace(['%', '\\'], '', (string) $search));

        return $term === '' ? null : mb_substr($term, 0, 80);
    }

    /**
     * @param  Builder<Product>  $query
     */
    private static function search(Builder $query, string $term, string $companyId): void
    {
        $query->where(function (Builder $q) use ($term, $companyId) {
            $q->where('products.name', 'like', '%'.$term.'%')
                ->orWhere('products.brand', 'like', '%'.$term.'%')
                ->orWhere('products.sku', 'like', $term.'%')
                ->orWhereIn('products.id', ProductBarcode::query()->select('product_id')->where('company_id', $companyId)->where('barcode', $term));
        });
    }
}
