<?php

namespace App\Domain\Catalogue\Import;

use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\TillProfile;

/**
 * What a product CSV column can be mapped to, and a first guess from the header names.
 */
final class ImportColumns
{
    /** @var array<string, array{label: string, help: string, aliases: list<string>}> */
    public const FIELDS = [
        'barcode' => ['label' => 'Barcode', 'help' => 'Finds the product to update. Added to the product if it is new to it.', 'aliases' => ['barcode', 'ean', 'ean13', 'upc', 'gtin', 'plu', 'barcode number']],
        'sku' => ['label' => 'Product code (SKU)', 'help' => 'Finds the product when there is no barcode match.', 'aliases' => ['sku', 'code', 'product code', 'item code', 'stock code', 'ref', 'reference', 'plu code']],
        'name' => ['label' => 'Name', 'help' => 'Needed for a new product.', 'aliases' => ['name', 'product name', 'product', 'item', 'item name', 'title', 'product description']],
        'short_name' => ['label' => 'Short name (till button)', 'help' => 'Up to 40 characters. The name is used when empty.', 'aliases' => ['short name', 'till name', 'button name', 'short description']],
        'brand' => ['label' => 'Brand', 'help' => '', 'aliases' => ['brand', 'manufacturer', 'make']],
        'description' => ['label' => 'Description', 'help' => '', 'aliases' => ['description', 'long description', 'notes']],
        'department' => ['label' => 'Department', 'help' => 'By name. A department you do not have yet is created.', 'aliases' => ['department', 'dept', 'department name']],
        'category' => ['label' => 'Category', 'help' => 'By name, in the department. Created when new.', 'aliases' => ['category', 'cat', 'category name', 'group', 'product group']],
        'vat' => ['label' => 'VAT rate', 'help' => 'Your VAT code (S, R, Z) or the percentage (20, 5, 0).', 'aliases' => ['vat', 'vat rate', 'vat code', 'tax', 'tax rate', 'vat %']],
        'sell_price' => ['label' => 'Sell price', 'help' => 'In pounds, including VAT. Every shop\'s price.', 'aliases' => ['price', 'sell price', 'selling price', 'retail price', 'rrp', 'price inc vat', 'sell', 'sale price']],
        'cost_price' => ['label' => 'Cost price', 'help' => 'In pounds, up to 4 decimal places.', 'aliases' => ['cost', 'cost price', 'unit cost', 'buy price', 'cost ex vat']],
        'unit_code' => ['label' => 'Unit', 'help' => 'The till unit code, e.g. PCS or KG.', 'aliases' => ['unit', 'unit code', 'uom', 'unit of measure']],
        'age_rule' => ['label' => 'Age restriction', 'help' => '16, 18 or a till age rule such as tobaccoGenerational. Empty or "none" for none.', 'aliases' => ['age', 'age restriction', 'age rule', 'min age']],
        'track_stock' => ['label' => 'Track stock', 'help' => 'Yes or no.', 'aliases' => ['track stock', 'stocked', 'stock tracked']],
        'min_stock_qty' => ['label' => 'Minimum stock', 'help' => '', 'aliases' => ['min stock', 'minimum stock', 'min qty']],
        'reorder_qty' => ['label' => 'Reorder quantity', 'help' => '', 'aliases' => ['reorder qty', 'reorder quantity', 'reorder level', 'reorder']],
        'is_active' => ['label' => 'Active', 'help' => 'Yes or no. "No" archives the product.', 'aliases' => ['active', 'is active', 'enabled', 'status']],
    ];

    /**
     * A first mapping from the header names: field → column index. Each field and column is used once.
     *
     * @param  list<string>  $headers
     * @return array<string, int>
     */
    public static function guess(array $headers): array
    {
        $mapping = [];

        foreach ($headers as $index => $header) {
            $normal = self::normal($header);

            foreach (self::fields() as $field => $definition) {
                if (! isset($mapping[$field]) && in_array($normal, [...$definition['aliases'], self::normal($definition['label'])], true)) {
                    $mapping[$field] = $index;
                    break;
                }
            }
        }

        return $mapping;
    }

    /**
     * Keeps only known fields pointing at an existing column, each column once.
     *
     * @param  array<mixed>  $mapping
     * @param  list<string>  $headers
     * @return array<string, int>
     */
    public static function clean(array $mapping, array $headers): array
    {
        $clean = [];

        foreach ($mapping as $field => $index) {
            if (isset(self::FIELDS[$field]) && is_numeric($index) && isset($headers[(int) $index]) && ! in_array((int) $index, $clean, true)) {
                $clean[(string) $field] = (int) $index;
            }
        }

        return $clean;
    }

    /**
     * @return list<array{value: string, label: string, help: string}>
     */
    public static function options(): array
    {
        return array_map(fn (string $field, array $d) => ['value' => $field, 'label' => $d['label'], 'help' => $d['help']], array_keys(self::FIELDS), self::fields());
    }

    /**
     * FIELDS in this instance's words (Pakistan plan P3): GB exactly as written; elsewhere the profile's tax name
     * ("GST rate") and currency ("In rupees"), with the tax name's header names ("gst", "gst rate") recognised too.
     *
     * @return array<string, array{label: string, help: string, aliases: list<string>}>
     */
    public static function fields(): array
    {
        $country = app(Country::class);

        if ($country->is(Country::DEFAULT)) {
            return self::FIELDS;
        }

        $tax = strtolower($country->taxName());
        $fields = array_map(fn (array $d) => [
            'label' => $country->taxText($d['label']),
            'help' => str_replace('In pounds', 'In '.$country->currencyName(), $country->taxText($d['help'])),
            'aliases' => array_values(array_unique([...$d['aliases'], ...array_map(fn (string $a) => (string) preg_replace('/\bvat\b/', $tax, $a), $d['aliases'])])),
        ], self::FIELDS);

        // Pak POS pack: one age rule, eighteen (a file's other till rules are still read and kept as written).
        if (TillProfile::ageRules() !== null) {
            $fields['age_rule']['help'] = '18 for an age check. Empty or "none" for none.';
        }

        return $fields;
    }

    private static function normal(string $header): string
    {
        return trim((string) preg_replace('/[^a-z0-9%]+/', ' ', strtolower($header)));
    }
}
