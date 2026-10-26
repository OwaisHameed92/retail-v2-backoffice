<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\OnlineStore\Data\CatalogueFilters;
use App\Domain\OnlineStore\Data\CatalogueItem;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Enums\AgeRule;
use App\Domain\TillData\Enums\MedicineClassificationClass;
use App\Domain\TillData\Models\Product;
use Carbon\CarbonImmutable;
use Closure;
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
 * products the shop's till has marked inactive there (`branch_products.is_active` false), products under a recall
 * still open at the shop and products outside their availability dates (CatalogueSafetyRules, audit OSA-4, OSA-23).
 * Stock: tracked when the shop's stock line says so, else when the product does; on hand as the till last reported
 * it (no stock line = 0). Tracked with 0 or less: shown, not orderable. OS-13: a business with no live till that sells
 * without tills (online store only) uses the shop's own stock instead (ManualStock); its price is the business price.
 */
final class OnlineCatalogueQuery
{
    /**
     * Why a product is not shown, as the store builder's products page says it (OS-3), in the order the rules are
     * checked. Every key is one rule of base().
     */
    public const REASONS = [
        'inactive' => 'Not active on the till',
        'archived' => 'Archived or merged',
        'hidden' => 'Hidden by you',
        'ageRestricted' => 'Age-restricted',
        'medicine' => 'Pharmacy or prescription medicine',
        'alcohol' => 'Alcohol',
        'tobacco' => 'Tobacco',
        'lottery' => 'Lottery',
        'knife' => 'Knife or blade',
        'banned' => 'Banned item',
        'vape' => 'Vape',
        'openPrice' => 'Open price (no shelf price)',
        'variantParent' => 'Has variants (the variants are shown instead)',
        'recalled' => CatalogueSafetyRules::REASONS['recalled'],
        'unavailable' => CatalogueSafetyRules::REASONS['unavailable'],
        'noName' => CatalogueSafetyRules::REASONS['noName'],
        'notAtShop' => 'Switched off at this shop',
        'noPrice' => 'No price at this shop',
    ];

    /** Product flags that keep a product off line (null counts as false), by the reason they give. */
    private const NEVER_FLAGS = ['alcohol' => 'is_alcohol', 'tobacco' => 'is_tobacco', 'lottery' => 'is_lottery', 'knife' => 'is_knife',
        'banned' => 'is_banned', 'vape' => 'vape_duty_applies', 'openPrice' => 'is_open_price', 'variantParent' => 'is_variant_parent'];

    /**
     * Products the store shows at this shop (no select yet: count it, or call select()).
     *
     * @return Builder<Product>
     */
    public static function base(OnlineStoreShop $shop, ?CarbonImmutable $at = null): Builder
    {
        $query = self::joined($shop);

        foreach (self::rules($shop, $at) as $rule) {
            $rule($query);
        }

        return $query;
    }

    /**
     * Why each of these products of the business is not shown at the shop: product id => the keys of every rule it
     * fails (REASONS order), an empty list when it is shown. Read only: one query for the ids, then one per rule for
     * the products not shown (callers pass one page of ids).
     *
     * @param  list<string>  $ids
     * @return array<string, list<string>>
     */
    public static function exclusions(OnlineStoreShop $shop, array $ids, ?CarbonImmutable $at = null): array
    {
        $out = array_fill_keys($ids, []);
        $shown = self::base($shop, $at)->whereIn('products.id', $ids)->pluck('products.id')->all();
        $rest = array_values(array_diff($ids, $shown));

        if ($rest === []) {
            return $out;
        }

        foreach (self::rules($shop, $at) as $reason => $rule) {
            $query = self::joined($shop)->whereIn('products.id', $rest);
            $rule($query);

            foreach (array_diff($rest, $query->pluck('products.id')->all()) as $id) {
                $out[$id][] = $reason;
            }
        }

        return $out;
    }

    /**
     * The shop's shelf price of a product row (`products` in the query): its live shop price for the base unit, else
     * the business sell price. SQL and its bindings.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function shelfPrice(OnlineStoreShop $shop, ?CarbonImmutable $at = null): array
    {
        return self::price($shop, $at);
    }

    /**
     * The business's products with the joins the rules read.
     *
     * @return Builder<Product>
     */
    private static function joined(OnlineStoreShop $shop): Builder
    {
        $companyId = $shop->company_id;

        return Product::query()
            ->where('products.company_id', $companyId)
            ->leftJoin('online_store_products as osp', fn (JoinClause $j) => $j->on('osp.product_id', '=', 'products.id')->where('osp.company_id', '=', $companyId))
            ->leftJoin('categories as cat', fn (JoinClause $j) => $j->on('cat.id', '=', 'products.category_id')->where('cat.company_id', '=', $companyId));
    }

    /**
     * Each rule keeps the products that pass it (on a joined() query), keyed by the reason a product fails it.
     *
     * @return array<string, Closure(Builder<Product>): mixed>
     */
    private static function rules(OnlineStoreShop $shop, ?CarbonImmutable $at): array
    {
        $companyId = $shop->company_id;
        [$price, $bindings] = self::price($shop, $at);
        $off = fn (string $column) => fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull($column)->orWhere($column, false));

        $rules = [
            'inactive' => fn (Builder $q) => $q->where('products.is_active', true),
            'archived' => fn (Builder $q) => $q->whereNull('products.archived_at')->whereNull('products.merged_into_product_id'),
            'hidden' => $off('osp.hidden'),
            'ageRestricted' => fn (Builder $q) => $q
                ->where(fn (Builder $w) => $w->whereNull('products.age_rule')->orWhere('products.age_rule', AgeRule::None->value))
                ->where(fn (Builder $w) => $w->whereNull('cat.age_rule_default')->orWhere('cat.age_rule_default', AgeRule::None->value))
                ->where(fn (Builder $w) => $w->whereNull('products.is_age_restricted')->orWhere('products.is_age_restricted', false)),
            'medicine' => fn (Builder $q) => $q->whereNotExists(fn (QueryBuilder $s) => $s->selectRaw('1')->from('medicine_classifications as mc')
                ->whereColumn('mc.product_id', 'products.id')->where('mc.company_id', $companyId)->whereNull('mc.deleted_at')
                ->whereIn('mc.class', [MedicineClassificationClass::PharmacyOnly->value, MedicineClassificationClass::PrescriptionOnly->value])),
        ];

        foreach (self::NEVER_FLAGS as $reason => $flag) {
            $rules[$reason] = $off("products.{$flag}");
        }

        return $rules + CatalogueSafetyRules::rules($shop, $at) + [
            'notAtShop' => fn (Builder $q) => $q->whereNotExists(fn (QueryBuilder $s) => $s->selectRaw('1')->from('branch_products as bpi')
                ->whereColumn('bpi.product_id', 'products.id')->where('bpi.company_id', $companyId)->where('bpi.branch_id', $shop->branch_id)
                ->whereNull('bpi.deleted_at')->where('bpi.is_active', false)),
            'noPrice' => fn (Builder $q) => $q->whereRaw("{$price} > 0", $bindings),
        ];
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

        $query->select(['products.id', 'products.name', 'products.description', 'products.image_path', 'products.department_id', 'products.category_id',
            'products.is_weighed', 'products.unit_code', 'products.max_qty_per_sale', 'products.track_stock', 'products.net_mass_kg', 'products.volume_ml', 'products.is_deposit_item', 'products.deposit_amount'])
            ->addSelect(['osp.description as online_description', 'osp.photo_path as online_photo', 'osp.allergen_info as online_allergens'])
            ->selectRaw("{$price} as online_price", $bindings);

        // OS-13: no live till (online store only): the shop's own stock, never the till tables.
        if (ManualStock::applies($shop->company_id)) {
            return $query->selectRaw(ManualStock::TRACKED_SQL.' as shop_tracked')->selectRaw(ManualStock::QTY_SQL.' as shop_qty');
        }

        return $query->selectRaw($line('is_stock_tracked').' as shop_tracked', [$shop->company_id, $shop->branch_id])
            ->selectRaw('COALESCE('.$line('qty_on_hand').', 0) as shop_qty', [$shop->company_id, $shop->branch_id]);
    }

    /**
     * The public store's list filters (OS-2): only products orderable now (not tracked, or tracked with stock at the
     * shop, as item() decides) and a price range on the shop's price (the bound limit is cast, so SQLite compares
     * numbers, not text).
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public static function filter(Builder $query, OnlineStoreShop $shop, CatalogueFilters $filters, ?CarbonImmutable $at = null): Builder
    {
        [$price, $bindings] = self::price($shop, $at);
        $line = fn (string $column) => "(select bpf.{$column} from branch_products bpf where bpf.product_id = products.id and bpf.company_id = ? "
            .'and bpf.branch_id = ? and bpf.deleted_at is null order by bpf.id limit 1)';
        $shopBindings = [$shop->company_id, $shop->branch_id];

        $manual = $filters->inStockOnly && ManualStock::applies($shop->company_id);

        return $query
            ->when($manual, fn (Builder $q) => $q->whereRaw(ManualStock::IN_STOCK_SQL))
            ->when($filters->inStockOnly && ! $manual, fn (Builder $q) => $q->whereRaw(
                '(COALESCE('.$line('is_stock_tracked').', products.track_stock, 0) = 0 OR COALESCE('.$line('qty_on_hand').', 0) > 0)',
                [...$shopBindings, ...$shopBindings],
            ))
            ->when($filters->minPrice !== null, fn (Builder $q) => $q->whereRaw("{$price} >= CAST(? AS DECIMAL(12,2))", [...$bindings, $filters->minPrice]))
            ->when($filters->maxPrice !== null, fn (Builder $q) => $q->whereRaw("{$price} <= CAST(? AS DECIMAL(12,2))", [...$bindings, $filters->maxPrice]))
            ->when($filters->homeRow || $filters->featurable, fn (Builder $q) => HomeRowRules::apply($q, $shop, $filters->featurable, $at));
    }

    /**
     * The list's order (after select(), which names the price `online_price`); the id breaks ties so pages never
     * overlap.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public static function order(Builder $query, CatalogueFilters $filters): Builder
    {
        $sorted = match ($filters->sort) {
            CatalogueFilters::SORT_PRICE_ASC => $query->orderBy('online_price')->orderBy('products.name'),
            CatalogueFilters::SORT_PRICE_DESC => $query->orderByDesc('online_price')->orderBy('products.name'),
            CatalogueFilters::SORT_NEWEST => $query->orderByRaw('products.created_at IS NULL')->orderByDesc('products.created_at')->orderBy('products.name'),
            default => $query->orderBy('products.name'),
        };

        return $sorted->orderBy('products.id');
    }

    /** One selected row as the store shows it. */
    public static function item(Product $row): CatalogueItem
    {
        $attributes = $row->getAttributes();
        $tracked = $attributes['shop_tracked'] ?? null;
        $tracked = $tracked === null ? (bool) ($attributes['track_stock'] ?? false) : (bool) $tracked;
        $description = trim((string) ($attributes['online_description'] ?? '')) !== '' ? (string) $attributes['online_description'] : $row->description;
        $max = $attributes['max_qty_per_sale'] ?? null;
        $isWeighed = (bool) ($attributes['is_weighed'] ?? false);

        return new CatalogueItem(
            id: $row->id,
            name: (string) $row->name,
            description: $description !== null && trim($description) !== '' ? $description : null,
            photoPath: ($attributes['online_photo'] ?? null) ?: ($row->image_path ?: null),
            departmentId: $row->department_id,
            price: Money::normalise($attributes['online_price'] ?? 0, 2),
            isWeighed: $isWeighed,
            unitCode: (string) ($attributes['unit_code'] ?? ''),
            maxQtyPerSale: $max === null || (int) $max <= 0 ? null : (int) $max,
            stockTracked: $tracked,
            orderable: ! $tracked || Money::compare($attributes['shop_qty'] ?? 0, 0) > 0,
            categoryId: isset($attributes['category_id']) ? (string) $attributes['category_id'] : null,
            allergenInfo: trim((string) ($attributes['online_allergens'] ?? '')) !== '' ? trim((string) $attributes['online_allergens']) : null,
            netMassKg: self::positive($attributes['net_mass_kg'] ?? null),
            volumeMl: self::positive($attributes['volume_ml'] ?? null),
            stockLimit: $tracked ? self::stepDown(Money::normalise($attributes['shop_qty'] ?? 0, 4), $isWeighed) : null,
            deposit: (bool) ($attributes['is_deposit_item'] ?? false) && is_numeric($attributes['deposit_amount'] ?? null)
                && (float) $attributes['deposit_amount'] > 0 ? Money::normalise($attributes['deposit_amount'], 2) : null,
        );
    }

    /** A stock quantity down to what can be ordered: whole units, or 0.25 kg steps; never below 0 (OSA-12). */
    public static function stepDown(string $qty, bool $weighed): string
    {
        if (Money::compare($qty, '0') <= 0) {
            return '0.0000';
        }

        return $weighed ? Money::mul(bcdiv(Money::mul($qty, '4', 4), '1', 0), '0.25', 4) : Money::normalise(bcdiv($qty, '1', 0), 4);
    }

    /** A positive decimal as a string, else null. */
    private static function positive(mixed $value): ?string
    {
        return is_numeric($value) && (float) $value > 0 ? Money::normalise($value, 4) : null;
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
