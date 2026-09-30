<?php

namespace App\Domain\Pricing\Queries;

use App\Domain\Pricing\Support\ShopPrices;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\TillData\Models\BranchPrice;
use App\Domain\TillData\Models\Department;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductBarcode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Props for the shop prices list (module 4.3): one row per active product with the business price and each shop's
 * price now (its own, or the business price). A one-shop user sees only their shop. `filter=own` keeps products
 * that have a shop price live or scheduled in the shops shown.
 */
final class PriceList
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request): array
    {
        $tenancy = app(CurrentCompany::class);
        $shops = ShopPrices::shops();
        $shopIds = $shops->pluck('id')->all();
        $now = CarbonImmutable::now('UTC');
        $at = $now->format('Y-m-d H:i:s');
        $filter = $request->query('filter') === 'own' ? 'own' : 'all';
        $department = is_string($request->query('department')) && preg_match('/^[0-9A-Za-z]{26}$/', (string) $request->query('department')) === 1
            ? (string) $request->query('department') : null;

        $table = TableQuery::from($request)->sortable(['name', 'sell_price'])->defaultSort('name');
        $search = $table->search();
        $query = Product::query()->where('is_active', true)
            ->when($department !== null, fn (Builder $q) => $q->where('department_id', $department))
            ->when($filter === 'own', fn (Builder $q) => $q->whereIn('id', self::withOwnPrice($shopIds, $at)))
            ->when($search !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('name', 'like', '%'.$search.'%')
                ->orWhere('sku', 'like', $search.'%')
                ->orWhereIn('id', ProductBarcode::query()->select('product_id')->where('barcode', $search))));

        $page = $table->paginate($query);
        $ids = array_map(fn (Product $p) => $p->id, $page['data']);
        $live = ShopPrices::live($ids, $shopIds, $now);
        $scheduled = BranchPrice::query()->whereIn('product_id', $ids)->whereIn('branch_id', $shopIds)->where('valid_from_utc', '>', $at)
            ->where(fn (Builder $q) => $q->whereNull('valid_to_utc')->orWhereColumn('valid_to_utc', '>', 'valid_from_utc'))
            ->selectRaw('product_id, count(*) as n')->groupBy('product_id')->pluck('n', 'product_id')->all();

        $page['data'] = array_map(function (Product $p) use ($live, $scheduled, $shopIds) {
            $prices = [];
            foreach ($shopIds as $shopId) {
                $row = $live[$p->id][$shopId][''] ?? null;
                $prices[$shopId] = $row === null ? null : ['price' => (string) $row->price, 'validTo' => $row->valid_to_utc?->toIso8601ZuluString(), 'changedAt' => $row->origin_branch_id !== null ? 'shop' : 'portal'];
            }

            return [
                'id' => $p->id, 'name' => (string) $p->name, 'sku' => $p->sku, 'sellPrice' => (string) $p->sell_price,
                'shopPrices' => $prices, 'scheduled' => (int) ($scheduled[$p->id] ?? 0),
            ];
        }, $page['data']);

        return [
            'products' => $page,
            'shops' => $shops->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'code' => $b->code, 'isActive' => (bool) $b->is_active])->values()->all(),
            'filters' => ['filter' => $filter, 'department' => $department],
            'departments' => Department::query()->orderBy('name')->get(['id', 'name'])->map(fn ($d) => ['value' => $d->id, 'label' => (string) $d->name])->all(),
            'counts' => [
                'products' => Product::query()->where('is_active', true)->count(),
                'withOwnPrice' => BranchPrice::query()->whereIn('branch_id', $shopIds)->liveAt($at)->reorder()->distinct()->count('product_id'),
                'livePrices' => BranchPrice::query()->whereIn('branch_id', $shopIds)->liveAt($at)->reorder()->count(),
                'scheduled' => BranchPrice::query()->whereIn('branch_id', $shopIds)->where('valid_from_utc', '>', $at)
                    ->where(fn (Builder $q) => $q->whereNull('valid_to_utc')->orWhereColumn('valid_to_utc', '>', 'valid_from_utc'))->count(),
            ],
            'restrictedShop' => $tenancy->restrictedBranchId(),
            'canSetShopPrices' => $tenancy->can(Ability::PricesManage),
        ];
    }

    /**
     * Products with a shop price live now or scheduled in the given shops.
     *
     * @param  list<string>  $shopIds
     * @return Builder<BranchPrice>
     */
    private static function withOwnPrice(array $shopIds, string $at): Builder
    {
        return BranchPrice::query()->select('product_id')->whereIn('branch_id', $shopIds)
            ->where(fn (Builder $q) => $q->whereNull('valid_to_utc')->orWhere(fn (Builder $w) => $w->where('valid_to_utc', '>', $at)->whereColumn('valid_to_utc', '>', 'valid_from_utc')));
    }
}
