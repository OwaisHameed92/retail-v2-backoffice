<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\TillData\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * What the home page's product rows may show (OS-11), on an OnlineCatalogueQuery query (`products`, `osp` joined):
 *
 * - Audit OSA-31: never a product the till marks HFSS (`products.is_hfss`, high in fat, sugar or salt) in a home row
 *   (featured, on offer, rows by department). The UK HFSS placement rules keep these off an online store's home page.
 *   It is still sold and found by search and departments.
 * - The automatic featured row (best sellers, newest; `$featurable`) also leaves out tiny service items: a shelf price
 *   under {@see self::MIN_PRICE}, a name or department naming carrier bags or a bag charge, and products the shop took
 *   out of "featured" on the Products tab (`online_store_products.not_featured`).
 *
 *     HomeRowRules::apply($query, $shop, featurable: true);
 *     $ids = HomeRowRules::keep($shop, $rankedIds, featurable: true);
 */
final class HomeRowRules
{
    /** The lowest shelf price (pounds) the automatic featured row shows. */
    public const MIN_PRICE = '0.50';

    /** Lower-case words that mark a carrier bag or bag charge, in a product's or department's name. */
    public const SERVICE_WORDS = ['carrier', 'bag charge', 'bag levy', 'plastic bag', 'paper bag'];

    /**
     * @param  Builder<Product>  $query
     */
    public static function apply(Builder $query, OnlineStoreShop $shop, bool $featurable, ?CarbonImmutable $at = null): void
    {
        $query->where(fn (Builder $w) => $w->whereNull('products.is_hfss')->orWhere('products.is_hfss', false));

        if (! $featurable) {
            return;
        }

        [$price, $bindings] = OnlineCatalogueQuery::shelfPrice($shop, $at);
        $query->whereRaw("{$price} >= CAST(? AS DECIMAL(12,2))", [...$bindings, self::MIN_PRICE])
            ->where(fn (Builder $w) => $w->whereNull('osp.not_featured')->orWhere('osp.not_featured', false));

        foreach (self::SERVICE_WORDS as $word) {
            $query->whereRaw('LOWER(products.name) NOT LIKE ?', ['%'.$word.'%']);
        }

        $query->whereNotExists(function (QueryBuilder $s) use ($shop) {
            $s->selectRaw('1')->from('departments as hrd')->whereColumn('hrd.id', 'products.department_id')
                ->where('hrd.company_id', $shop->company_id)
                ->where(function (QueryBuilder $names) {
                    foreach (self::SERVICE_WORDS as $word) {
                        $names->orWhereRaw('LOWER(hrd.name) LIKE ?', ['%'.$word.'%']);
                    }
                });
        });
    }

    /**
     * The ids (in their order) the store shows at the shop that may go in a home row: HFSS left out, and with
     * `$featurable` the automatic featured row's rules too. Run inside StoreScope.
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    public static function keep(OnlineStoreShop $shop, array $ids, bool $featurable): array
    {
        if ($ids === []) {
            return [];
        }

        $query = OnlineCatalogueQuery::base($shop)->whereIn('products.id', $ids);
        self::apply($query, $shop, $featurable);
        $allowed = array_flip(array_map('strval', $query->pluck('products.id')->all()));

        return array_values(array_filter($ids, fn (string $id) => isset($allowed[$id])));
    }
}
