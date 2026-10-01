<?php

namespace App\Domain\Demo\Catalogue;

use App\Domain\Reporting\Demo\DemoCatalogue;

/**
 * The demo catalogue (`demo:seed`, `demo:sales`): DemoCatalogue's 35 best sellers plus every size of every DemoRange
 * line, ~600 products. Prices inc VAT and costs ex VAT in pence; barcodes are EAN-13 with a valid check digit (UK
 * "50" prefix, newspapers and magazines "977"). Built once per process; the same for every business, so a product
 * key always means the same thing.
 *
 * @phpstan-type DemoProduct array{key: string, name: string, barcode: string, price: int, cost: int, vat: string, weight: float, when: string, ageRule: string, category: string, department: string, case: int, alcohol: bool, tobacco: bool, expiry: bool, hfss: bool, legacy: bool}
 */
final class DemoProducts
{
    /** @var array<string, DemoProduct>|null */
    private static ?array $all = null;

    /**
     * @return array<string, DemoProduct>
     */
    public static function all(): array
    {
        return self::$all ??= self::build();
    }

    /**
     * @return DemoProduct
     */
    public static function get(string $key): array
    {
        return self::all()[$key];
    }

    /** EAN-13 from 12 digits: the GS1 check digit appended. */
    public static function ean13(string $twelve): string
    {
        $sum = 0;

        foreach (str_split($twelve) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }

        return $twelve.((10 - $sum % 10) % 10);
    }

    public static function isValidEan13(string $code): bool
    {
        return preg_match('/^\d{13}$/', $code) === 1 && self::ean13(substr($code, 0, 12)) === $code;
    }

    /**
     * @return array<string, DemoProduct>
     */
    private static function build(): array
    {
        $products = [];
        $names = [];

        foreach (DemoCatalogue::PRODUCTS as $key => [$name, $barcode, $price, $cost, $vat, $weight, $when, $age]) {
            $category = DemoRange::LEGACY_CATEGORY[$key];
            $products[$key] = self::product($key, $name, self::ean13(substr($barcode, 0, 12)), $price, $cost, $vat, $weight * 4.0, $when, $category, true, $age);
            $names[strtolower($name)] = true;
        }

        foreach (DemoRange::CATEGORIES as $category => $c) {
            $lines = [];

            foreach ($c[9] as $line) {
                [$base, $sizes] = explode('|', $line, 2);

                foreach (explode(',', $sizes) as $size) {
                    [$label, $price] = explode(':', $size);
                    $lines[] = [trim($base.' '.$label), (int) $price];
                }
            }

            foreach ($lines as [$name, $price]) {
                if (isset($names[strtolower($name)])) {
                    continue;
                }

                $names[strtolower($name)] = true;
                $key = self::key($name, $products);
                $jitter = 0.4 + (abs(crc32($key.'|w')) % 1200) / 1000;
                $weight = round($c[5] * 12 * $jitter / count($lines), 3);
                $percentage = DemoCatalogue::VAT[$c[2]];
                $net = $price * 100 / (100 + $percentage);
                $cost = (int) max(1, round($net * (100 - $c[3]) / 100 * (0.95 + (abs(crc32($key.'|c')) % 100) / 1000)));
                $prefix = $c[0] === 'newspapers' ? '977' : '50';
                $digits = str_pad((string) (abs(crc32($key)) % 10 ** (12 - strlen($prefix))), 12 - strlen($prefix), '0', STR_PAD_LEFT);
                $products[$key] = self::product($key, $name, self::ean13($prefix.$digits), $price, $cost, $c[2], $weight, $c[6], $category, false, $c[7] !== 'none');
            }
        }

        return self::uniqueBarcodes($products);
    }

    /**
     * @return DemoProduct
     */
    private static function product(string $key, string $name, string $barcode, int $price, int $cost, string $vat, float $weight, string $when, string $category, bool $legacy, bool $age): array
    {
        $c = DemoRange::CATEGORIES[$category];

        return [
            'key' => $key, 'name' => $name, 'barcode' => $barcode, 'price' => $price, 'cost' => $cost, 'vat' => $vat,
            'weight' => $weight, 'when' => $when, 'ageRule' => $age ? ($c[7] === 'none' ? 'over18' : $c[7]) : 'none',
            'category' => $category, 'department' => $c[0], 'case' => $c[4], 'alcohol' => in_array('alcohol', $c[8], true),
            'tobacco' => in_array('tobacco', $c[8], true), 'expiry' => in_array('expiry', $c[8], true), 'hfss' => in_array('hfss', $c[8], true),
            'legacy' => $legacy,
        ];
    }

    /**
     * @param  array<string, mixed>  $taken
     */
    private static function key(string $name, array $taken): string
    {
        $base = substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-'), 0, 60);
        $key = $base;

        for ($i = 2; isset($taken[$key]); $i++) {
            $key = "{$base}-{$i}";
        }

        return $key;
    }

    /**
     * A barcode is never on two products: a clash gets the next free number.
     *
     * @param  array<string, DemoProduct>  $products
     * @return array<string, DemoProduct>
     */
    private static function uniqueBarcodes(array $products): array
    {
        $seen = [];

        foreach ($products as $key => $product) {
            $code = $product['barcode'];

            while (isset($seen[$code])) {
                $code = self::ean13(str_pad((string) (((int) substr($code, 0, 12)) + 1), 12, '0', STR_PAD_LEFT));
            }

            $seen[$code] = true;
            $products[$key]['barcode'] = $code;
        }

        return $products;
    }
}
