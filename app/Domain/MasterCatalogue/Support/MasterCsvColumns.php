<?php

namespace App\Domain\MasterCatalogue\Support;

/**
 * The columns of a master catalogue CSV (docs/master-catalogue.md), found by header name in any order.
 * Each field accepts a few common names, so most supplier files load without editing. Unknown columns are ignored.
 */
final class MasterCsvColumns
{
    /** Field => header names it is recognised by (lower case, spaces/underscores/dashes ignored). */
    public const FIELDS = [
        'barcode' => ['barcode', 'ean', 'ean13', 'gtin', 'upc', 'productbarcode', 'outerbarcode'],
        'name' => ['name', 'productname', 'description', 'product', 'title', 'itemdescription'],
        'brand' => ['brand', 'brandname', 'manufacturer'],
        'size' => ['size', 'packsize', 'unitsize'],
        'size_unit' => ['unit', 'sizeunit', 'uom', 'unitofmeasure'],
        'pack_qty' => ['packqty', 'multipack', 'packcount', 'units'],
        'department' => ['department', 'dept'],
        'category' => ['category', 'subdepartment', 'subcategory'],
        'vat_rate' => ['vat', 'vatrate', 'vatpercent', 'tax'],
        'rrp' => ['rrp', 'retailprice', 'price', 'sellprice'],
        'age_rule' => ['age', 'agerule', 'agerestriction', 'agerestricted'],
        'image_url' => ['image', 'imageurl', 'picture'],
        'in_starter_packs' => ['starter', 'starterpack', 'core', 'corerange'],
    ];

    public const TEMPLATE = ['barcode', 'name', 'brand', 'size', 'unit', 'pack_qty', 'department', 'category', 'vat', 'rrp', 'age_rule', 'image_url', 'starter'];

    /**
     * Field => column index, for the headers found.
     *
     * @param  list<string>  $headers
     * @return array<string, int>
     */
    public static function map(array $headers): array
    {
        $map = [];

        foreach ($headers as $index => $header) {
            $key = preg_replace('/[\s_\-%()]+/', '', strtolower($header)) ?? '';

            foreach (self::FIELDS as $field => $names) {
                if (! isset($map[$field]) && in_array($key, $names, true)) {
                    $map[$field] = $index;

                    break;
                }
            }
        }

        return $map;
    }

    /**
     * One row as SaveMasterProduct input: only the columns the file has, and only the cells that are not empty
     * (an empty cell keeps the catalogue's value).
     *
     * @param  array<string, int>  $map
     * @param  list<string>  $cells
     * @return array<string, mixed>
     */
    public static function read(array $map, array $cells): array
    {
        $cell = fn (string $field) => isset($map[$field]) ? trim($cells[$map[$field]] ?? '') : '';
        $input = [];

        foreach (['barcode', 'name', 'brand', 'department', 'category', 'vat_rate', 'rrp', 'age_rule', 'image_url', 'in_starter_packs'] as $field) {
            if ($cell($field) !== '') {
                $input[$field] = $cell($field);
            }
        }

        if ($cell('size') !== '' || $cell('size_unit') !== '' || $cell('pack_qty') !== '') {
            $size = PackSize::fromCells($cell('size'), $cell('size_unit'), $cell('pack_qty'));
            $input += ['size_value' => $size->value, 'size_unit' => $size->unit, 'pack_qty' => $size->pack];
        } elseif (isset($input['name']) && ! isset($map['size'])) {
            $size = PackSize::fromName($input['name']);

            if ($size->value !== null) {
                $input += ['size_value' => $size->value, 'size_unit' => $size->unit, 'pack_qty' => $size->pack];
            }
        }

        return $input;
    }
}
