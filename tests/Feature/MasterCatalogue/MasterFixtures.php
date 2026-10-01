<?php

namespace Tests\Feature\MasterCatalogue;

use App\Domain\MasterCatalogue\Enums\MasterSource;
use App\Domain\MasterCatalogue\Models\MasterProduct;

/** Master catalogue test data: products straight into the platform-wide table, and a complete admin form. */
final class MasterFixtures
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function product(array $attributes = [], MasterSource $source = MasterSource::Admin): MasterProduct
    {
        $product = new MasterProduct;
        $product->forceFill([
            'barcode' => '5000157024671', 'name' => 'Heinz Baked Beans 415g', 'brand' => null, 'size_value' => '415', 'size_unit' => 'g',
            'department' => 'Grocery', 'category' => 'Tins and jars', 'vat_rate' => '0', 'rrp' => '1.40', 'age_rule' => 'none',
            'in_starter_packs' => false, 'source' => $source, ...$attributes,
        ])->save();

        return $product;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function form(array $overrides = []): array
    {
        return [
            'barcode' => '5000157024671', 'name' => 'Heinz Baked Beans 415g', 'brand' => 'Heinz', 'size_value' => '415', 'size_unit' => 'g',
            'pack_qty' => null, 'department' => 'Grocery', 'category' => 'Tins and jars', 'vat_rate' => '0', 'rrp' => '1.40',
            'age_rule' => 'none', 'image_url' => null, 'in_starter_packs' => true, ...$overrides,
        ];
    }
}
