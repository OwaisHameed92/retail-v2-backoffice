<?php

namespace App\Domain\Shared\Support;

use InvalidArgumentException;

/**
 * Decimal maths on strings with bcmath. Never floats.
 *
 * Money (prices, totals) uses scale 2; costs and quantities use scale 4. Rounding is half away from zero,
 * matching the till (`Domain/Common/Money.cs`).
 *
 *     Money::add('1.10', '2.205');          // "3.31"
 *     Money::compare('1.5', '1.50');        // 0
 *     Money::normalise(1.005);              // "1.01"
 *     Money::normalise('1.23456', 4);       // "1.2346"
 */
final class Money
{
    public const SCALE = 2;

    public const QUANTITY_SCALE = 4;

    /** Internal precision for intermediate results before the final rounding. */
    private const WORK_SCALE = 12;

    /**
     * Parse and round a number or numeric string to a fixed-scale string ("1.4500" → "1.45").
     *
     * @throws InvalidArgumentException when the value is not numeric.
     */
    public static function normalise(mixed $value, int $scale = self::SCALE): string
    {
        return self::round(self::parse($value), $scale);
    }

    public static function add(mixed $a, mixed $b, int $scale = self::SCALE): string
    {
        return self::round(bcadd(self::parse($a), self::parse($b), self::WORK_SCALE), $scale);
    }

    public static function sub(mixed $a, mixed $b, int $scale = self::SCALE): string
    {
        return self::round(bcsub(self::parse($a), self::parse($b), self::WORK_SCALE), $scale);
    }

    public static function mul(mixed $a, mixed $b, int $scale = self::SCALE): string
    {
        return self::round(bcmul(self::parse($a), self::parse($b), self::WORK_SCALE), $scale);
    }

    /**
     * @param  iterable<mixed>  $values
     */
    public static function sum(iterable $values, int $scale = self::SCALE): string
    {
        $total = '0';

        foreach ($values as $value) {
            $total = bcadd($total, self::parse($value), self::WORK_SCALE);
        }

        return self::round($total, $scale);
    }

    /**
     * @return int -1, 0 or 1
     */
    public static function compare(mixed $a, mixed $b): int
    {
        return bccomp(self::parse($a), self::parse($b), self::WORK_SCALE);
    }

    public static function equals(mixed $a, mixed $b): bool
    {
        return self::compare($a, $b) === 0;
    }

    public static function isZero(mixed $value): bool
    {
        return self::compare($value, '0') === 0;
    }

    public static function isNegative(mixed $value): bool
    {
        return self::compare($value, '0') < 0;
    }

    /**
     * Round a plain decimal string half away from zero.
     */
    public static function round(string $value, int $scale = self::SCALE): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';
        $negative = str_starts_with($value, '-');
        // bcmath truncates towards zero, so adding ±half then truncating rounds half away from zero.
        $rounded = $negative
            ? bcsub($value, $half, $scale)
            : bcadd($value, $half, $scale);

        // Avoid "-0.00".
        if (bccomp($rounded, '0', $scale) === 0) {
            return bcadd('0', '0', $scale);
        }

        return $rounded;
    }

    /**
     * Turn an int, float or numeric string into a plain decimal string bcmath accepts.
     *
     * @throws InvalidArgumentException
     */
    public static function parse(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException('The amount must be a finite number.');
            }
            // PHP writes the shortest representation that round-trips (1.005 → "1.005").
            $value = (string) $value;
            if (str_contains($value, 'E')) {
                $value = rtrim(rtrim(sprintf('%.'.self::WORK_SCALE.'F', (float) $value), '0'), '.');
            }
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('The amount must be a number.');
        }

        $value = trim($value);

        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $value, $m) !== 1 || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new InvalidArgumentException("The amount \"{$value}\" is not a number.");
        }

        $sign = $m[1] === '-' ? '-' : '';
        $whole = $m[2] === '' ? '0' : $m[2];
        $fraction = $m[3] ?? '';

        return $sign.$whole.($fraction === '' ? '' : '.'.$fraction);
    }
}
