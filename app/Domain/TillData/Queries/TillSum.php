<?php

namespace App\Domain\TillData\Queries;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;

/**
 * Exact sums of decimal columns, computed by the database, returned as fixed-scale strings. Never PHP floats.
 *
 * SQLite stores decimals as REAL, so a plain SUM() drifts (0.1 + 0.2). Each value is first scaled to whole units
 * of its last decimal place and rounded (`ROUND(total * 100)`), which is exact on SQLite (integers in a double
 * are exact up to 2^53) and on MySQL (DECIMAL arithmetic), then scaled back with bcmath.
 *
 *     TillSum::of(Sale::query()->completed()->forBranch($branch), 'total');          // "1234.56"
 *     TillSum::many($query, ['total' => 2, 'vat_total' => 2]);                       // ['total' => "…", …]
 */
final class TillSum
{
    /**
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     */
    public static function of(BuilderContract $query, string $column, int $scale = 2): string
    {
        return self::many($query, [$column => $scale])[$column];
    }

    /**
     * Several sums in one query.
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     * @param  array<string, int>  $columns  column => scale
     * @return array<string, string>
     */
    public static function many(BuilderContract $query, array $columns): array
    {
        $base = $query instanceof EloquentBuilder ? $query->clone()->toBase() : (clone $query);
        $qualify = $query instanceof EloquentBuilder ? $query->getModel()->qualifyColumn(...) : fn (string $c) => $c;
        $grammar = $base->getGrammar();
        $selects = [];

        foreach ($columns as $column => $scale) {
            $wrapped = $grammar->wrap($qualify($column));
            $alias = $grammar->wrap('sum_'.$column);
            $selects[] = new Expression('SUM(ROUND('.$wrapped.' * '.(10 ** $scale).')) as '.$alias);
        }

        $row = (array) $base->reorder()->select($selects)->first();
        $sums = [];

        foreach ($columns as $column => $scale) {
            $sums[$column] = self::fromUnits($row['sum_'.$column] ?? null, $scale);
        }

        return $sums;
    }

    /**
     * Whole units (pence, ten-thousandths…) from the database back to a decimal string.
     */
    public static function fromUnits(mixed $units, int $scale): string
    {
        $units = match (true) {
            $units === null => '0',
            is_float($units) => sprintf('%.0f', $units),
            default => explode('.', (string) $units)[0],
        };

        return bcdiv($units, (string) (10 ** $scale), $scale);
    }
}
