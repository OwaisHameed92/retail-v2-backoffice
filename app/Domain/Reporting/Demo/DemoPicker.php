<?php

namespace App\Domain\Reporting\Demo;

use App\Domain\Demo\Catalogue\DemoProducts;
use Random\Randomizer;

/**
 * Picks a demo product for a basket line by popularity and time of day (milk and papers in the morning, alcohol in
 * the evening), in O(log n): the cumulative weights of the ~600 products are worked out once per time band.
 */
final class DemoPicker
{
    /** @var array<int, array{keys: list<string>, sums: list<float>}> band => cumulative table */
    private static array $tables = [];

    public static function product(Randomizer $rng, int $hour): string
    {
        $band = match (true) {
            $hour < 11 => 0,
            $hour < 12 => 1,
            $hour < 17 => 2,
            default => 3,
        };
        $table = self::$tables[$band] ??= self::table($band);
        $target = $rng->nextFloat() * $table['sums'][count($table['sums']) - 1];
        [$low, $high] = [0, count($table['sums']) - 1];

        while ($low < $high) {
            $mid = intdiv($low + $high, 2);

            if ($table['sums'][$mid] > $target) {
                $high = $mid;
            } else {
                $low = $mid + 1;
            }
        }

        return $table['keys'][$low];
    }

    /**
     * Up to `$count` different products for one basket.
     *
     * @return list<string>
     */
    public static function distinct(Randomizer $rng, int $hour, int $count): array
    {
        $keys = [];

        for ($attempt = 0; $attempt < $count * 4 && count($keys) < $count; $attempt++) {
            $keys[self::product($rng, $hour)] = true;
        }

        return array_map('strval', array_keys($keys));
    }

    /**
     * @return array{keys: list<string>, sums: list<float>}
     */
    private static function table(int $band): array
    {
        $keys = [];
        $sums = [];
        $sum = 0.0;

        foreach (DemoProducts::all() as $key => $p) {
            $sum += $p['weight'] * match (true) {
                $p['when'] === 'morning' && $band === 0 => 2.2,
                $p['when'] === 'evening' && $band === 3 => 2.4,
                $p['when'] === 'evening' && $band <= 1 => 0.3,
                default => 1.0,
            };
            $keys[] = (string) $key;
            $sums[] = $sum;
        }

        return ['keys' => $keys, 'sums' => $sums];
    }
}
