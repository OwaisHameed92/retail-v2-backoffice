<?php

namespace App\Domain\TillData\Sync;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One company's rows found by a list of keys (the primary key, or a child's indexed parent column), in chunks, with
 * the company checked on the rows read rather than in the WHERE.
 *
 * Why: for `company_id = ? AND id IN (…)` SQLite's planner (no ANALYZE statistics) prefers any `(company_id, …)`
 * index once the list holds more than a handful of keys, and then reads every row the company has ever stored, so a
 * push got slower with every sale the business made. Looking up by the key alone always uses its index: the work is
 * O(keys), whatever the history. MySQL 8 picks the key's index for these queries either way.
 *
 *     CompanyRows::whereIn('sales', $companyId, 'id', $saleIds, ['id', 'branch_id']);
 *     CompanyRows::whereIn('sale_lines', $companyId, 'sale_id', $saleIds, ['id'], fn ($q) => $q->whereNull('register_id'));
 */
final class CompanyRows
{
    public const CHUNK = 500;

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $columns  `company_id` is added when missing
     * @param  (callable(Builder): mixed)|null  $where  further conditions; never `company_id` (the planner trap above)
     * @return list<object>
     */
    public static function whereIn(string $table, string $companyId, string $column, array $keys, array $columns, ?callable $where = null): array
    {
        $select = in_array('*', $columns, true) ? ['*'] : array_values(array_unique([...$columns, 'company_id']));
        $rows = [];

        foreach (array_chunk(array_values(array_unique($keys)), self::CHUNK) as $chunk) {
            $query = DB::table($table)->whereIn($column, $chunk);

            if ($where !== null) {
                $where($query);
            }

            foreach ($query->get($select) as $row) {
                if ((string) $row->company_id === $companyId) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * The ids of the company's rows found by whereIn().
     *
     * @param  list<string>  $keys
     * @param  (callable(Builder): mixed)|null  $where
     * @return list<string>
     */
    public static function ids(string $table, string $companyId, string $column, array $keys, ?callable $where = null): array
    {
        return array_map(fn (object $row) => (string) $row->id, self::whereIn($table, $companyId, $column, $keys, ['id'], $where));
    }
}
