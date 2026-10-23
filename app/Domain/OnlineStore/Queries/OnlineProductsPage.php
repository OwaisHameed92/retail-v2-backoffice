<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Support\OnlineCatalogueQuery;
use App\Domain\OnlineStore\Support\StoreImages;
use App\Domain\Shared\Support\Money;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Department;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductBarcode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;

/**
 * The portal's online products page (OS-3, `app/online-store/products`): the business's active products with what
 * the store does with them at one shop (its shelf price, shown or not and why, OnlineCatalogueQuery's rules) and the
 * online details (hidden, photo, short description). Filters: shop, status (all, shown, not shown, hidden by you),
 * department; search by name, code prefix or exact barcode. One page of rows, then the reasons for that page.
 */
final class OnlineProductsPage
{
    public const STATUSES = ['all', 'shown', 'notShown', 'hidden'];

    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request, Company $company, ?string $onlyBranchId, bool $canEdit): array
    {
        $shops = Branch::query()->where('company_id', $company->id)->where('is_active', true)
            ->when($onlyBranchId !== null, fn ($q) => $q->whereKey($onlyBranchId))->orderBy('name')->get(['id', 'name']);
        $store = OnlineStore::query()->where('company_id', $company->id)->first();
        $collection = $store === null ? collect() : OnlineStoreShop::query()->where('company_id', $company->id)
            ->where('online_store_id', $store->id)->where('enabled', true)->get()->keyBy('branch_id');
        $wanted = $request->query('shop');
        $shopId = is_string($wanted) && $shops->contains('id', $wanted) ? $wanted
            : ($shops->first(fn (Branch $b) => $collection->has($b->id)) ?? $shops->first())?->id;
        $status = in_array($request->query('status'), self::STATUSES, true) ? (string) $request->query('status') : 'all';
        $department = self::id($request->query('department'));

        $base = [
            'shops' => $shops->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name, 'collects' => $collection->has($b->id)])->values()->all(),
            'filters' => ['shop' => $shopId, 'status' => $status, 'department' => $department],
            'departments' => Department::query()->where('company_id', $company->id)->orderBy('position')->orderBy('name')->get(['id', 'name'])
                ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name])->all(),
            'hasStore' => $store !== null,
            'canEdit' => $canEdit,
            'descriptionMax' => 1000,
        ];

        if ($shopId === null) {
            return $base + ['products' => ['data' => [], 'meta' => ['page' => 1, 'perPage' => 25, 'total' => 0, 'lastPage' => 1]],
                'counts' => ['total' => 0, 'shown' => 0, 'hidden' => 0]];
        }

        $shop = $collection->get($shopId) ?? new OnlineStoreShop(['company_id' => $company->id, 'branch_id' => $shopId]);
        $table = TableQuery::from($request)->sortable(['name'])->defaultSort('name');
        [$price, $bindings] = OnlineCatalogueQuery::shelfPrice($shop);
        $shown = fn () => OnlineCatalogueQuery::base($shop)->select('products.id');

        $query = self::listable($company)
            ->select(['products.id', 'products.name', 'products.sku', 'products.department_id', 'products.description', 'products.is_weighed'])
            ->addSelect(['osp.hidden as online_hidden', 'osp.photo_path as online_photo', 'osp.description as online_description'])
            ->selectRaw("{$price} as shelf_price", $bindings)
            ->when($department !== null, fn (Builder $q) => $q->where('products.department_id', $department))
            ->when($table->search() !== null, fn (Builder $q) => self::search($q, (string) $table->search()))
            ->when($status === 'shown', fn (Builder $q) => $q->whereIn('products.id', $shown()))
            ->when($status === 'notShown', fn (Builder $q) => $q->whereNotIn('products.id', $shown()))
            ->when($status === 'hidden', fn (Builder $q) => $q->where('osp.hidden', true));

        $page = $table->paginate($query);
        $reasons = OnlineCatalogueQuery::exclusions($shop, array_map(fn (Product $p) => $p->id, $page['data']));
        $names = Department::query()->where('company_id', $company->id)->pluck('name', 'id');
        $page['data'] = array_map(fn (Product $p) => self::row($p, $reasons[$p->id] ?? [], $names[$p->department_id] ?? null), $page['data']);

        return $base + [
            'products' => $page,
            'counts' => [
                'total' => self::listable($company)->count(),
                'shown' => OnlineCatalogueQuery::base($shop)->count(),
                'hidden' => self::listable($company)->where('osp.hidden', true)->count(),
            ],
        ];
    }

    /**
     * @param  list<string>  $reasons
     * @return array<string, mixed>
     */
    private static function row(Product $p, array $reasons, ?string $department): array
    {
        $attributes = $p->getAttributes();
        $price = $attributes['shelf_price'] ?? null;

        return [
            'id' => $p->id,
            'name' => (string) $p->name,
            'sku' => $p->sku,
            'department' => $department,
            'price' => $price === null ? null : Money::normalise($price, 2),
            'isWeighed' => (bool) ($attributes['is_weighed'] ?? false),
            'photoUrl' => StoreImages::url($attributes['online_photo'] ?? null),
            'description' => $attributes['online_description'] ?? null,
            'tillDescription' => $p->description,
            'hidden' => (bool) ($attributes['online_hidden'] ?? false),
            'shown' => $reasons === [],
            'reasons' => array_values(array_map(fn (string $r) => OnlineCatalogueQuery::REASONS[$r], array_filter($reasons, fn (string $r) => $r !== 'hidden'))),
        ];
    }

    /**
     * The products this page lists: active, not archived or merged, with their online row.
     *
     * @return Builder<Product>
     */
    private static function listable(Company $company): Builder
    {
        return Product::query()
            ->where('products.company_id', $company->id)
            ->where('products.is_active', true)
            ->whereNull('products.archived_at')
            ->whereNull('products.merged_into_product_id')
            ->leftJoin('online_store_products as osp', fn (JoinClause $j) => $j->on('osp.product_id', '=', 'products.id')->where('osp.company_id', '=', $company->id));
    }

    /**
     * Part of the name, a code prefix or an exact barcode (as the products list).
     *
     * @param  Builder<Product>  $query
     */
    private static function search(Builder $query, string $term): void
    {
        $query->where(fn (Builder $q) => $q->where('products.name', 'like', '%'.$term.'%')
            ->orWhere('products.sku', 'like', $term.'%')
            ->orWhereIn('products.id', ProductBarcode::query()->select('product_id')->where('barcode', $term)));
    }

    private static function id(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[0-9A-Za-z]{26}$/', $value) === 1 ? $value : null;
    }
}
