<?php

namespace App\Domain\Reporting\Support;

use Illuminate\Database\Query\Expression;

/**
 * Exact decimal sums on MySQL 8 and SQLite (which stores decimals as REAL): each value is scaled to whole units of
 * its last decimal place and rounded in SQL (`SUM(ROUND(x * 100))`), then added up and scaled back with bcmath.
 * Same idea as TillData's TillSum, for grouped queries. Never PHP floats.
 */
final class Units
{
    /**
     * SUM of a raw SQL expression in units of 10^-scale.
     *
     * @return Expression<non-falsy-string>
     */
    public static function sum(string $sql, int $scale, string $alias): Expression
    {
        return new Expression('SUM(ROUND(('.$sql.') * '.(10 ** $scale).')) as '.$alias);
    }

    /**
     * SUM of the expression over rows matching a raw SQL condition (portable `FILTER (WHERE …)`).
     *
     * @return Expression<non-falsy-string>
     */
    public static function sumIf(string $condition, string $sql, int $scale, string $alias): Expression
    {
        return new Expression('SUM(CASE WHEN '.$condition.' THEN ROUND(('.$sql.') * '.(10 ** $scale).') ELSE 0 END) as '.$alias);
    }

    /**
     * COUNT of rows matching a raw SQL condition.
     *
     * @return Expression<non-falsy-string>
     */
    public static function countIf(string $condition, string $alias): Expression
    {
        return new Expression('SUM(CASE WHEN '.$condition.' THEN 1 ELSE 0 END) as '.$alias);
    }

    /** A units value from the database (int, float holding an integer, numeric string or null) as an integer string. */
    public static function of(mixed $value): string
    {
        return match (true) {
            $value === null => '0',
            is_int($value) => (string) $value,
            is_float($value) => sprintf('%.0f', $value),
            default => explode('.', (string) $value)[0] ?: '0',
        };
    }

    public static function add(string ...$values): string
    {
        $total = '0';

        foreach ($values as $value) {
            $total = bcadd($total, $value, 0);
        }

        return $total;
    }

    public static function sub(string $a, string $b): string
    {
        return bcsub($a, $b, 0);
    }

    public static function neg(string $a): string
    {
        return bcsub('0', $a, 0);
    }

    /** Units back to a fixed-scale decimal string ("515", 2 → "5.15"). */
    public static function decimal(string $units, int $scale): string
    {
        $value = bcdiv($units, (string) (10 ** $scale), $scale);

        return bccomp($value, '0', $scale) === 0 ? bcadd('0', '0', $scale) : $value;
    }

    /** A stored decimal (string, float from SQLite, int) as units of 10^-scale, rounded half away from zero. */
    public static function fromDecimal(mixed $value, int $scale): string
    {
        $text = match (true) {
            $value === null => '0',
            is_float($value) => rtrim(rtrim(sprintf('%.8F', $value), '0'), '.'),
            default => (string) $value,
        };
        $scaled = bcmul($text === '' || $text === '-' ? '0' : $text, (string) (10 ** $scale), 8);
        $half = str_starts_with($scaled, '-') ? '-0.5' : '0.5';

        return bcadd($scaled, $half, 0);
    }
}
