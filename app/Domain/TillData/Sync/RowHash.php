<?php

namespace App\Domain\TillData\Sync;

use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Registry\EntityDefinition;
use BackedEnum;

/**
 * The content hash of a hub-owned row (`hub_hash`): SHA-256 over the stored value of every schema field plus the
 * delete flag, each normalised by type so a mapped payload, a database row (any driver) and a model's attributes
 * hash the same. Timestamps, versions and sync columns are left out: an unchanged row that a till re-stamps or
 * re-versions still hashes the same. Contract §19.2: identical content is "already applied", not a change. Ledger-derived
 * columns (OwnershipRules::DERIVED_COLUMNS, a customer's balance and points, §10.1) are left out too: a till's cached
 * sum is not an edit of the row.
 */
final class RowHash
{
    private const SCALES = ['money' => 2, 'cost' => 4, 'quantity' => 4, 'percent' => 4, 'rate' => 6];

    /**
     * @param  array<string, mixed>  $row  column => value
     */
    public static function of(EntityDefinition $def, array $row): string
    {
        $parts = [];
        $derived = OwnershipRules::derivedColumns($def->entity);

        foreach ($def->fields as $field) {
            foreach ($field->columns() as $column) {
                if ($derived !== [] && in_array($column, $derived, true)) {
                    continue;
                }

                $parts[$column] = self::normalise($field->type, $row[$column] ?? null);
            }
        }

        $parts['deleted_at'] = self::normalise('datetime', $row['deleted_at'] ?? null);

        return hash('sha256', (string) json_encode($parts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function normalise(string $type, mixed $value): string|int|null
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            $value = $value->format($type === 'date' ? 'Y-m-d' : 'Y-m-d H:i:s');
        }

        return match ($type) {
            'money', 'cost', 'quantity', 'percent', 'rate' => Money::normalise($value, self::SCALES[$type]),
            'bool', 'int', 'bigint' => (int) $value,
            'date' => substr((string) $value, 0, 10),
            'datetime' => substr((string) $value, 0, 19),
            'json' => (string) json_encode(is_string($value) ? json_decode($value, true) : $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            default => is_scalar($value) ? (string) $value : (string) json_encode($value),
        };
    }
}
