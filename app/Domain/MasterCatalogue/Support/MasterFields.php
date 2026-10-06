<?php

namespace App\Domain\MasterCatalogue\Support;

use App\Domain\Shared\Country\Country;
use App\Domain\TillData\Enums\AgeRule;

/**
 * Cleans and checks master catalogue values from the admin form, a CSV row or an approved contribution: trims text,
 * fixes decimals to the column scale, keeps only known units and age rules. Returns the clean values and the
 * errors keyed by field (empty when all is well). Only the keys given are returned.
 */
final class MasterFields
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, string|int|null>, 1: array<string, string>}
     */
    public static function clean(array $input): array
    {
        $values = [];
        $errors = [];
        $text = fn (string $key, int $max) => ($v = trim((string) ($input[$key] ?? ''))) === '' ? null : mb_substr($v, 0, $max);

        if (array_key_exists('barcode', $input)) {
            $values['barcode'] = Gtin::normalise((string) $input['barcode']);

            if ($values['barcode'] === null) {
                $errors['barcode'] = 'Enter a valid EAN-13, EAN-8, UPC-A or GTIN-14 barcode (the check digit must match).';
            } elseif (Gtin::isInStore($values['barcode'])) {
                $errors['barcode'] = 'This is an in-store number (weighed or internal goods), not a product barcode.';
            }
        }

        if (array_key_exists('name', $input)) {
            $values['name'] = $text('name', 255);
            $errors += $values['name'] === null ? ['name' => 'Enter the product name.'] : [];
        }

        foreach (['brand' => 120, 'department' => 120, 'category' => 120, 'image_url' => 500] as $key => $max) {
            if (array_key_exists($key, $input)) {
                $values[$key] = $text($key, $max);
            }
        }

        if (($values['image_url'] ?? null) !== null && preg_match('#^https://\S+$#i', (string) $values['image_url']) !== 1) {
            $errors['image_url'] = 'The image address must start with https://.';
        }

        foreach (['size_value' => [4, 0, 1000000], 'vat_rate' => [2, 0, 100], 'rrp' => [2, 0, 100000]] as $key => [$places, $min, $max]) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            $raw = str_replace([app(Country::class)->symbol(), '%', ','], '', trim((string) $input[$key]));
            $values[$key] = null;

            if ($raw !== '' && (! is_numeric($raw) || (float) $raw < $min || (float) $raw > $max)) {
                $errors[$key] = "Enter a number from {$min} to {$max}.";
            } elseif ($raw !== '') {
                $values[$key] = number_format((float) $raw, $places, '.', '');
            }
        }

        if (array_key_exists('size_unit', $input)) {
            $unit = strtolower(trim((string) $input['size_unit']));
            $values['size_unit'] = $unit === '' ? null : $unit;
            $errors += $unit !== '' && ! in_array($unit, PackSize::UNITS, true) ? ['size_unit' => 'Choose g, kg, ml, cl, l, pack or each.'] : [];
        }

        if (array_key_exists('pack_qty', $input)) {
            $pack = trim((string) $input['pack_qty']);
            $values['pack_qty'] = ctype_digit($pack) && (int) $pack > 1 && (int) $pack <= 500 ? (int) $pack : null;
        }

        if (array_key_exists('in_starter_packs', $input)) {
            $values['in_starter_packs'] = filter_var($input['in_starter_packs'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }

        if (array_key_exists('age_rule', $input)) {
            $values['age_rule'] = self::ageRule((string) $input['age_rule']);
            $errors += $values['age_rule'] === null ? ['age_rule' => 'Choose an age check.'] : [];
            $values['age_rule'] ??= 'none';
        }

        return [$values, $errors];
    }

    /** The till's AgeRule value of a form or CSV cell ("over18", "18", "yes", "tobacco"…); '' = none; null = unknown. */
    public static function ageRule(string $value): ?string
    {
        $value = trim($value);
        $lower = strtolower($value);

        if (in_array($lower, ['', 'no', 'n', 'none', '0', 'false'], true)) {
            return 'none';
        }

        foreach (AgeRule::cases() as $rule) {
            if (strtolower($rule->value) === $lower) {
                return $rule->value;
            }
        }

        return match ($lower) {
            'yes', 'y', 'true', '1', '18', '18+', 'alcohol' => AgeRule::Over18->value,
            '16', '16+' => AgeRule::Over16->value,
            'tobacco', 'cigarettes' => AgeRule::TobaccoGenerational->value,
            'vape', 'vapes', 'nicotine' => AgeRule::Nicotine->value,
            default => null,
        };
    }
}
