<?php

namespace App\Domain\Operations\Support;

use Illuminate\Support\Facades\DB;

/**
 * Batch id → name lookups across every business for the admin "Sync & jobs" lists (admin only: plain query builder,
 * no tenant scope; deleted rows included so old problems still show a name).
 */
final class TenancyNames
{
    /**
     * @param  list<string|null>  $ids
     * @return array<string, string>
     */
    public static function companies(array $ids): array
    {
        return self::names('companies', $ids, fn (object $row) => (string) $row->name);
    }

    /**
     * @param  list<string|null>  $ids
     * @return array<string, string>
     */
    public static function branches(array $ids): array
    {
        return self::names('branches', $ids, fn (object $row) => trim($row->name.($row->code ? " ({$row->code})" : '')));
    }

    /**
     * @param  list<string|null>  $ids
     * @return array<string, string>
     */
    public static function registers(array $ids): array
    {
        return self::names('registers', $ids, fn (object $row) => trim($row->name.($row->code ? " ({$row->code})" : '')));
    }

    /**
     * @param  list<string|null>  $ids
     * @param  callable(object): string  $label
     * @return array<string, string>
     */
    private static function names(string $table, array $ids, callable $label): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => is_string($id) && $id !== '')));

        if ($ids === []) {
            return [];
        }

        $columns = $table === 'companies' ? ['id', 'name'] : ['id', 'name', 'code'];
        $names = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table($table)->whereIn('id', $chunk)->get($columns) as $row) {
                $names[(string) $row->id] = $label($row);
            }
        }

        return $names;
    }
}
