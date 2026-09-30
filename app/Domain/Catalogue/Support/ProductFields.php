<?php

namespace App\Domain\Catalogue\Support;

use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Which Product columns the portal writes (module 4.2) and the values a new product starts with.
 *
 * Everything else on the row is left exactly as the till last sent it: `nearest_expiry_date` (worked out by the till
 * from its stock batches), `merged_into_product_id`, `image_path` (a path on the till), the variant and availability
 * members (not edited here yet), and the sync columns. Prices here are the "every shop" price (`sell_price`); shop
 * prices are `BranchPrice` rows (module 4.3).
 */
final class ProductFields
{
    /** @var list<string> */
    public const EDITABLE = [
        'name', 'short_name', 'receipt_name', 'sku', 'brand', 'description',
        'department_id', 'category_id', 'sub_category_id', 'vat_rate_id',
        'unit_type', 'unit_code', 'is_weighed', 'is_open_price',
        'sell_price', 'cost_price', 'trade_price', 'pmp_price',
        'track_stock', 'min_stock_qty', 'max_stock_qty', 'reorder_qty', 'negative_stock_mode', 'bin_location', 'tracks_expiry_dates',
        'age_rule', 'max_qty_per_sale', 'max_qty_reason',
        'is_alcohol', 'abv_percent', 'volume_ml', 'is_tobacco', 'is_lottery', 'is_knife', 'is_banned', 'is_hfss', 'vape_duty_applies',
        'is_deposit_item', 'deposit_amount', 'commodity_code', 'net_mass_kg',
        'tile_colour_hex', 'tile_emoji', 'tile_position', 'is_active',
    ];

    /** @var list<string> */
    public const BOOLEANS = [
        'is_weighed', 'is_open_price', 'track_stock', 'tracks_expiry_dates', 'is_alcohol', 'is_tobacco', 'is_lottery', 'is_knife',
        'is_banned', 'is_hfss', 'vape_duty_applies', 'is_deposit_item', 'is_active',
    ];

    public const DEFAULT_TILE_COLOUR = '#1F6FEB';

    /**
     * Values of a new product before the input is applied (every non-null member of the Product schema has one).
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'unit_type' => 'pcs', 'unit_code' => 'PCS', 'cost_price' => '0', 'sell_price' => '0', 'track_stock' => true,
            'age_rule' => 'none', 'tracks_expiry_dates' => false, 'is_hfss' => false, 'is_deposit_item' => false, 'is_alcohol' => false,
            'is_tobacco' => false, 'is_lottery' => false, 'is_knife' => false, 'is_banned' => false, 'vape_duty_applies' => false,
            'tile_colour_hex' => self::DEFAULT_TILE_COLOUR, 'tile_position' => 0, 'is_active' => true, 'is_variant_parent' => false,
            'is_variant' => false, 'is_weighed' => false, 'is_open_price' => false, 'is_age_restricted' => false,
        ];
    }

    /**
     * A model's current values of the given columns in a comparable form (enum values, fixed-scale strings, ISO dates),
     * so "did anything change" never depends on how the database returned a decimal.
     *
     * @param  list<string>  $columns
     * @return array<string, bool|string|null>
     */
    public static function snapshot(Model $model, array $columns): array
    {
        $values = [];

        foreach ($columns as $column) {
            $values[$column] = self::comparable($model->getAttribute($column));
        }

        return $values;
    }

    public static function comparable(mixed $value): bool|string|null
    {
        return match (true) {
            $value === null => null,
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof CarbonInterface => $value->format('Y-m-d H:i:s'),
            is_bool($value) => $value,
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }

    /**
     * Keys whose comparable values differ.
     *
     * @param  array<string, bool|string|null>  $before
     * @param  array<string, bool|string|null>  $after
     * @return list<string>
     */
    public static function changed(array $before, array $after): array
    {
        return array_keys(array_filter($after, fn ($value, $key) => ($before[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));
    }
}
