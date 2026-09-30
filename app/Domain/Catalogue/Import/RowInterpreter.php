<?php

namespace App\Domain\Catalogue\Import;

use App\Domain\TillData\Enums\AgeRule;

/**
 * Turns one CSV row into an ImportRow with the mapping (field → column index): trims, reads money ("£1,299.50"),
 * yes/no, age rules ("18", "Over 18", "tobaccoGenerational"), and records a message for every cell it cannot use.
 * Nothing here reads the database.
 */
final class RowInterpreter
{
    private const MAX_PRICE = 99999999;

    /**
     * @param  array<string, int>  $mapping
     */
    public function __construct(private readonly array $mapping) {}

    /**
     * @param  list<string>  $cells
     */
    public function read(int $line, array $cells): ImportRow
    {
        $errors = [];
        $cell = fn (string $field): ?string => isset($this->mapping[$field]) && ($cells[$this->mapping[$field]] ?? '') !== '' ? $cells[$this->mapping[$field]] : null;
        $attributes = [];

        $barcode = $cell('barcode');

        if ($barcode !== null && preg_match('/^\d(\.\d+)?E\+\d+$/i', $barcode) === 1) {
            $errors[] = "Barcode {$barcode} was shortened by a spreadsheet. Format the column as text and export again.";
            $barcode = null;
        } elseif ($barcode !== null && preg_match('/^[0-9A-Za-z\-]{1,50}$/', $barcode) !== 1) {
            $errors[] = 'Barcode must be up to 50 letters or digits.';
            $barcode = null;
        }

        $sku = $cell('sku');

        if ($sku !== null && mb_strlen($sku) > 64) {
            $errors[] = 'Product code must be 64 characters or fewer.';
        }

        if ($barcode === null && $sku === null && $errors === []) {
            $errors[] = 'Needs a barcode or a product code to find or create the product.';
        }

        foreach (['name' => 255, 'short_name' => 40, 'brand' => 120, 'description' => 2000, 'unit_code' => 20] as $field => $max) {
            if (($value = $cell($field)) !== null) {
                mb_strlen($value) > $max ? $errors[] = ImportColumns::FIELDS[$field]['label']." must be {$max} characters or fewer." : $attributes[$field] = $value;
            }
        }

        if ($sku !== null) {
            $attributes['sku'] = $sku;
        }

        foreach (['sell_price' => 2, 'cost_price' => 4, 'min_stock_qty' => 4, 'reorder_qty' => 4] as $field => $places) {
            if (($value = $cell($field)) !== null) {
                $number = self::decimal($value, $places);
                $number === null ? $errors[] = ImportColumns::FIELDS[$field]['label']." \"{$value}\" is not an amount with up to {$places} decimal places." : $attributes[$field] = $number;
            }
        }

        foreach (['track_stock', 'is_active'] as $field) {
            if (($value = $cell($field)) !== null) {
                $bool = self::bool($value);
                $bool === null ? $errors[] = ImportColumns::FIELDS[$field]['label']." \"{$value}\" should be yes or no." : $attributes[$field] = $bool;
            }
        }

        if (($value = $cell('age_rule')) !== null) {
            $rule = self::ageRule($value);
            $rule === null ? $errors[] = "Age restriction \"{$value}\" is not one the till knows." : $attributes['age_rule'] = $rule;
        }

        $department = $cell('department');
        $category = $cell('category');

        foreach (['department' => $department, 'category' => $category] as $field => $value) {
            if ($value !== null && mb_strlen($value) > 100) {
                $errors[] = ImportColumns::FIELDS[$field]['label'].' must be 100 characters or fewer.';
            }
        }

        return new ImportRow($line, $barcode, $sku, $attributes, $department, $category, $cell('vat'), $errors);
    }

    public static function decimal(string $value, int $places): ?string
    {
        $value = str_replace(['£', ',', ' '], '', $value);

        if (preg_match('/^\d+(\.\d{1,'.$places.'})?$/', $value) !== 1 || (float) $value > self::MAX_PRICE) {
            return null;
        }

        return $value;
    }

    public static function bool(string $value): ?bool
    {
        return match (strtolower($value)) {
            'yes', 'y', 'true', '1', 'active', 'on' => true,
            'no', 'n', 'false', '0', 'archived', 'inactive', 'off' => false,
            default => null,
        };
    }

    public static function ageRule(string $value): ?string
    {
        $normal = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $value));

        foreach (AgeRule::cases() as $rule) {
            if (strtolower($rule->value) === $normal) {
                return $rule->value;
            }
        }

        return match ($normal) {
            'no', 'none', '0' => 'none',
            '16', 'over16', '16plus' => 'over16',
            '18', 'over18', '18plus' => 'over18',
            default => null,
        };
    }
}
