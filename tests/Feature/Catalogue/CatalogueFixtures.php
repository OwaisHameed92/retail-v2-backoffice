<?php

namespace Tests\Feature\Catalogue;

use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Models\Company;
use Tests\Feature\Sync\PullTestHelpers as Pull;

/**
 * Module 4.2 test data: a business's VAT rates, a department with a category and a sub-category, and two units, all
 * saved as the portal would (hub-owned). Ids are fresh ULIDs, so two businesses can each have their own.
 */
final class CatalogueFixtures
{
    /**
     * @return array{vat: string, zero: string, department: string, category: string, sub: string, each: string, case: string}
     */
    public static function seed(Company $company): array
    {
        $ids = ['vat' => Ulid::new(), 'zero' => Ulid::new(), 'department' => Ulid::new(), 'category' => Ulid::new(), 'sub' => Ulid::new(), 'each' => Ulid::new(), 'case' => Ulid::new()];
        $row = fn (string $entity, string $id, array $values) => Pull::portalCreate($company, $entity, Pull::payload($entity, $id, ['companyId' => $company->id, ...$values]));

        $row('VatRate', $ids['vat'], ['name' => 'Standard', 'code' => 'S', 'percentage' => 20, 'isDefault' => true, 'treatment' => 'apply']);
        $row('VatRate', $ids['zero'], ['name' => 'Zero', 'code' => 'Z', 'percentage' => 0, 'isDefault' => false, 'treatment' => 'apply']);
        $row('Department', $ids['department'], ['name' => 'Bakery', 'isActive' => true, 'colourHex' => '#C98A2B']);
        $row('Category', $ids['category'], ['name' => 'Bread', 'departmentId' => $ids['department'], 'parentCategoryId' => null, 'isActive' => true, 'ageRuleDefault' => 'none', 'colourHex' => '#C98A2B']);
        $row('Category', $ids['sub'], ['name' => 'Rolls', 'departmentId' => $ids['department'], 'parentCategoryId' => $ids['category'], 'isActive' => true, 'ageRuleDefault' => 'none', 'colourHex' => '#C98A2B']);
        $row('Unit', $ids['each'], ['code' => 'PCS', 'name' => 'Each', 'isActive' => true]);
        $row('Unit', $ids['case'], ['code' => 'CASE', 'name' => 'Case', 'isActive' => true]);

        return $ids;
    }

    /**
     * A complete product form submission.
     *
     * @param  array{vat: string, department: string, category: string}  $ids
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function form(array $ids, array $overrides = []): array
    {
        return [
            'name' => 'Toastie White 800g', 'short_name' => 'Toastie 800g', 'receipt_name' => null, 'sku' => 'WAR-800', 'brand' => 'Warburtons',
            'description' => null, 'department_id' => $ids['department'], 'category_id' => $ids['category'], 'sub_category_id' => null,
            'vat_rate_id' => $ids['vat'], 'unit_type' => 'pcs', 'unit_code' => 'PCS', 'sell_price' => '1.45', 'cost_price' => '0.9800',
            'trade_price' => null, 'pmp_price' => null, 'min_stock_qty' => '6', 'max_stock_qty' => null, 'reorder_qty' => '24',
            'negative_stock_mode' => null, 'bin_location' => null, 'age_rule' => 'none', 'max_qty_per_sale' => null, 'max_qty_reason' => null,
            'abv_percent' => null, 'volume_ml' => null, 'deposit_amount' => null, 'commodity_code' => null, 'net_mass_kg' => null,
            'tile_colour_hex' => '#C98A2B', 'tile_emoji' => null, 'tile_position' => 4, 'is_weighed' => false, 'is_open_price' => false,
            'track_stock' => true, 'tracks_expiry_dates' => false, 'is_alcohol' => false, 'is_tobacco' => false, 'is_lottery' => false,
            'is_knife' => false, 'is_banned' => false, 'is_hfss' => false, 'vape_duty_applies' => false, 'is_deposit_item' => false, 'is_active' => true,
            'barcodes' => [['id' => null, 'barcode' => '5010044000701', 'pack_qty' => 1, 'is_primary' => true]],
            'units' => [],
            ...$overrides,
        ];
    }
}
