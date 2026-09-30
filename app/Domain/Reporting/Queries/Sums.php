<?php

namespace App\Domain\Reporting\Queries;

use App\Domain\Reporting\Support\Units;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * Exact sums over reporting columns for the read side: decimals summed as whole units in SQL and scaled back with
 * bcmath (SQLite keeps decimals as REAL), counts as integers. Aliases are prefixed `sum_`.
 */
final class Sums
{
    /**
     * @param  array<string, int>  $decimals  column => scale
     * @param  list<string>  $counts
     * @return list<Expression<non-falsy-string>>
     */
    public static function select(string $table, array $decimals, array $counts = []): array
    {
        $grammar = DB::connection()->getQueryGrammar();
        $selects = [];

        foreach ($decimals as $column => $scale) {
            $selects[] = Units::sum($grammar->wrap($table.'.'.$column), $scale, $grammar->wrap('sum_'.$column));
        }

        foreach ($counts as $column) {
            $selects[] = new Expression('SUM('.$grammar->wrap($table.'.'.$column).') as '.$grammar->wrap('sum_'.$column));
        }

        return $selects;
    }

    /**
     * @param  array<string, int>  $decimals
     * @param  list<string>  $counts
     * @return array<string, string|int>
     */
    public static function read(?object $row, array $decimals, array $counts = []): array
    {
        $values = $row === null ? [] : (array) $row;
        $out = [];

        foreach ($decimals as $column => $scale) {
            $out[$column] = Units::decimal(Units::of($values['sum_'.$column] ?? null), $scale);
        }

        foreach ($counts as $column) {
            $out[$column] = (int) ($values['sum_'.$column] ?? 0);
        }

        return $out;
    }

    /** A raw SQL expression of a units sum, for ORDER BY. */
    public static function orderExpression(string $table, string $column, int $scale): string
    {
        return 'SUM(ROUND('.DB::connection()->getQueryGrammar()->wrap($table.'.'.$column).' * '.(10 ** $scale).'))';
    }
}
