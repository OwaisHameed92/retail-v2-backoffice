<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\OnlineStore\Data\CatalogueItem;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Enums\AgeRule;
use App\Domain\TillData\Enums\MedicineClassificationClass;
use App\Domain\TillData\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;

/**
 * The rules for what an online store shows at one shop (docs/online-store.md "What is shown"), as one query on the
 * till's tables, read only. Run inside StoreScope (the Product global scope) and filtered on the business explicitly
 * on every table it touches.
 *
 * Shown: active products that are not archived, deleted, merged or a variant parent, not hidden online, with a price
 * above zero at the shop: its own live shop price for the base unit (as the till reads it, ShopPrices: live from
 * `valid_from_utc` until `valid_to_utc`, latest start then highest id wins), else the business sell price.
 * Never shown: an age rule on the product or its category, `is_age_restricted`, alcohol, tobacco, lottery, knives,
 * banned, vape duty, open-price products (no shelf price), Pharmacy-only or Prescription-only medicines, and
 * products the shop's till has marked inactive there (`branch_products.is_active` false).
 * Stock: tracked when the shop's stock line says so, else when the product does; on hand as the till last reported
 * it (no stock line = 0). Tracked with 0 or less: shown, not orderable.
 */
final class OnlineCatalogueQuery
{
    /** Product flags that keep a product off line (null counts as false). */
    private const NEVER_FLAGS = ['is_age_restricted', 'is_alcohol', 'is_tobacco', 'is_lottery', 'is_knife', 'is_banned', 'vape_duty_applies',
        'is_open_price', 'is_variant_parent'];

    /**
     * Products the store shows at this shop (no select yet: count it, or call select()).
     *
     * @return Builder<Product>
     */
    public static function base(OnlineStoreShop $shop, ?CarbonImmutable $at = null): Builder
    {
        $companyId = $shop->company_id;
        [$price, $bindings] = self::price($shop, $at);

        $query = Product::query()
            ->where('products.company_id', $companyId)
            ->where('products.is_active', true)
            ->whereNull('products.archived_at')
            ->whereNull('products.merged_into_product_id')
            ->leftJoin('online_store_products as osp', fn (JoinClause $j) => $j->on('osp.product_id', '=', 'products.id')->where('osp.company_id', '=', $companyId))
            ->leftJoin('categories as cat', fn (JoinClause $j) => $j->on('cat.id', '=', 'products.category_id')->where('cat.company_id', '=', $companyId))
            ->where(fn (Builder $q) => $q->whereNull('osp.hidden')->orWhere('osp.hidden', false))
            ->where(fn (Builder $q) => $q->whereNull('products.age_rule')->orWhere('products.age_rule', AgeRule::None->value))
            ->where(fn (Builder $q) => $q->whereNull('cat.age_rule_default')->orWhere('cat.age_rule_default', AgeRule::None->value))
            ->whereNotExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('medicine_classifications as mc')
                ->whereColumn('mc.product_id', 'products.id')->where('mc.company_id', $companyId)->whereNull('mc.deleted_at')
                ->whereIn('mc.class', [MedicineClassificationClass::PharmacyOnly->value, MedicineClassificationClass::PrescriptionOnly->value]))
            ->whereNotExists(fn (QueryBuilder $q) => $q->selectRaw('1')->from('branch_products as bpi')
                ->whereColumn('bpi.product_id', 'products.id')->where('bpi.company_id', $companyId)->where('bpi.branch_id', $shop->branch_id)
                ->whereNull('bpi.deleted_at')->where('bpi.is_active', false))
            ->whereRaw("{$price} > 0", $bindings);

        foreach (self::NEVER_FLAGS as $flag) {
            $query->where(fn (Builder $q) => $q->whereNull("products.{$flag}")->orWhere("products.{$flag}", false));
        }

        return $query;
    }

    /**
     * The columns CatalogueItem needs.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public static function select(Builder $query, OnlineStoreShop $shop, ?CarbonImmutable $at = null): Builder
    {
        [$price, $bindings] = self::price($shop, $at);
        $line = fn (string $column) => "(select bps.{$column} from branch_products bps where bps.product_id = products.id and bps.company_id = ? "
            .'and bps.branch_id = ? and bps.deleted_at is null order by bps.id limit 1)';

        return $query->select(['products.id', 'products.name', 'products.description', 'products.image_path', 'products.department_id',
            'products.is_weighed', 'products.unit_code', 'products.max_qty_per_sale', 'products.track_stock'])
            ->addSelect(['osp.description as online_description', 'osp.photo_path as online_photo'])
            ->selectRaw("{$price} as online_price", $bindings)
            ->selectRaw($line('is_stock_tracked').' as shop_tracked', [$shop->company_id, $shop->branch_id])
            ->selectRaw('COALESCE('.$line('qty_on_hand').', 0) as shop_qty', [$shop->company_id, $shop->branch_id]);
    }

    /** One selected row as the store shows it. */
    public static function item(Product $row): CatalogueItem
    {
        $attributes = $row->getAttributes();
        $tracked = $attributes['shop_tracked'] ?? null;
        $tracked = $tracked === null ? (bool) ($attributes['track_stock'] ?? false) : (bool) $tracked;
        $description = trim((string) ($attributes['online_description'] ?? '')) !== '' ? (string) $attributes['online_description'] : $row->description;
        $max = $attributes['max_qty_per_sale'] ?? null;

        return new CatalogueItem(
            id: $row->id,
            name: (string) $row->name,
            description: $description !== null && trim($description) !== '' ? $description : null,
            photoPath: ($attributes['online_photo'] ?? null) ?: ($row->image_path ?: null),
            departmentId: $row->department_id,
            price: Money::normalise($attributes['online_price'] ?? 0, 2),
            isWeighed: (bool) ($attributes['is_weighed'] ?? false),
            unitCode: (string) ($attributes['unit_code'] ?? ''),
            maxQtyPerSale: $max === null || (int) $max <= 0 ? null : (int) $max,
            stockTracked: $tracked,
            orderable: ! $tracked || Money::compare($attributes['shop_qty'] ?? 0, 0) > 0,
        );
    }

    /**
     * The shop's price: its live shop price for the base unit, else the business sell price.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function price(OnlineStoreShop $shop, ?CarbonImmutable $at): array
    {
        $at = ($at ?? CarbonImmutable::now('UTC'))->utc()->format('Y-m-d H:i:s');
        $live = '(select bpr.price from branch_prices bpr where bpr.product_id = products.id and bpr.company_id = ? and bpr.branch_id = ? '
            .'and bpr.product_unit_id is null and bpr.deleted_at is null and bpr.valid_from_utc <= ? '
            .'and (bpr.valid_to_utc is null or bpr.valid_to_utc > ?) order by bpr.valid_from_utc desc, bpr.id desc limit 1)';

        return ["COALESCE({$live}, products.sell_price)", [$shop->company_id, $shop->branch_id, $at, $at]];
    }
}
