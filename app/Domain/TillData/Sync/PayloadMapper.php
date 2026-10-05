<?php

namespace App\Domain\TillData\Sync;

use App\Domain\Licensing\LicenceKey;
use App\Domain\Shared\Support\Redactor;
use App\Domain\TillData\Generator\FieldTyper;
use App\Domain\TillData\Registry\EntityDefinition;
use App\Domain\TillData\Registry\FieldDefinition;
use App\Domain\TillData\Sync\Data\MappedChange;
use App\Domain\TillData\Sync\Data\Rejection;
use App\Domain\TillData\Sync\Data\SyncChange;

/**
 * Validates a payload against its entity's field map and maps it to a complete table row. Only `id` and `companyId`
 * are required: a member an older till does not send yet is stored as null, or its type's default when the column
 * is not nullable (contract §17.11 rule 2: absent, null and "" mean the same). A member that is present must have
 * the right type. Members the schema does not know go to `extra`, so an additive till change
 * never loses data (secret-looking members are redacted); derived members are dropped. Also applies the scope rules that need no database:
 * a branch/register row must belong to the sending branch and one of its tills.
 */
final class PayloadMapper
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /** Members any payload may carry that are never stored (contract section 6, "Derived members"). */
    private const ALWAYS_DERIVED = ['isDeleted', 'domainEvents', 'key'];

    /**
     * What a missing (or null) member stands for when the type's default would be wrong: a Customer row from a till
     * before 0.1.32 has no `earnsPoints` and is read as true, never false (PORTAL-CHANGES-2026-10-06 §2.7).
     */
    private const ABSENT_DEFAULTS = ['Customer' => ['earnsPoints' => true]];

    /** Decimal types: [scale, digits before the point] (Values has the full rules). */
    private const DECIMALS = ['money' => [2, 10], 'cost' => [4, 10], 'quantity' => [4, 10], 'percent' => [4, 5], 'rate' => [6, 10]];

    /** @var array<string, array<string, true>> entity => members that are neither stored fields nor extra */
    private array $skip = [];

    public function map(SyncChange $change, EntityDefinition $def, SyncContext $context): MappedChange|Rejection
    {
        $payload = $change->payload;

        if ($payload === null) {
            return $change->isDelete()
                ? new MappedChange($change, $def, null)
                : Rejection::for($change, 'payload.missing', 'An insert or update must carry the whole row.');
        }

        if ($def->isKeyed()) {
            $change = KeyedRows::identify($change, $def, $context);

            if ($change instanceof Rejection) {
                return $change;
            }

            $payload = (array) $change->payload;
        } elseif (($payload['id'] ?? null) !== $change->entityId) {
            return Rejection::for($change, 'payload.id_mismatch', 'payload.id must equal entityId.');
        } elseif (($payload['companyId'] ?? null) !== $context->companyId) {
            return Rejection::for($change, 'sync.wrong_company', 'The row belongs to another company.');
        }

        $row = array_fill_keys($def->columns, null);
        $row['id'] = $change->entityId;
        $row['company_id'] = $context->companyId;
        $errors = [];
        $unknownEnums = [];

        foreach ($def->plan as [$name, $column, $type, $nullable, $max, $enum]) {
            if (($payload[$name] ?? null) === null && isset(self::ABSENT_DEFAULTS[$def->entity][$name])) {
                $row[$column] = Values::toColumn($def->fields[$name], self::ABSENT_DEFAULTS[$def->entity][$name]);

                continue;
            }

            if (! array_key_exists($name, $payload)) {
                if ($nullable) {
                    $row[$column] = null;
                } elseif ($type === 'secret') {
                    [$row[$column.'_hash'], $row[$column.'_last4']] = [null, null];
                } else {
                    $row[$column] = Values::toColumn($def->fields[$name], self::absentDefault($type, $change));
                }

                continue;
            }

            $value = $payload[$name];

            // Inline the commonest cases; everything else (and every error) goes through Values::toColumn.
            if ($value === null && $nullable) {
                $row[$column] = null;

                continue;
            }

            if ($type === 'string' && is_string($value) && strlen($value) <= (int) $max) {
                $row[$column] = $value;

                continue;
            }

            if ($type === 'bool' && is_bool($value)) {
                $row[$column] = (int) $value;

                continue;
            }

            if (isset(self::DECIMALS[$type]) && (is_float($value) || is_int($value)) && ($fast = self::fastDecimal($value, ...self::DECIMALS[$type])) !== null) {
                $row[$column] = $fast;

                continue;
            }

            try {
                $value = Values::toColumn($def->fields[$name], $value);
            } catch (InvalidValue $e) {
                $errors[] = "{$name} {$e->getMessage()}";

                continue;
            }

            if ($type === 'secret') {
                [$row[$column.'_hash'], $row[$column.'_last4']] = $this->secret($value);
            } else {
                $row[$column] = $value;

                if ($enum !== null && $value !== null && $enum::tryFrom($value) === null) {
                    $unknownEnums[] = "{$def->entity}.{$name}={$value}";
                }
            }
        }

        foreach (['createdAt' => 'created_at', 'updatedAt' => 'updated_at', 'deletedAt' => 'deleted_at'] as $member => $column) {
            try {
                $value = $payload[$member] ?? null;
                $row[$column] = $value === null ? null : Values::dateTime($value);
            } catch (InvalidValue $e) {
                $errors[] = "{$member} {$e->getMessage()}";
            }
        }

        if ($errors !== []) {
            $more = count($errors) > 5 ? ' (and '.(count($errors) - 5).' more)' : '';

            return Rejection::for($change, 'payload.invalid', "Invalid {$def->entity}: ".implode('; ', array_slice($errors, 0, 5)).$more.'.');
        }

        // A keyed row has no updatedAt of its own (RolePermission): the change's time orders it (EntityWriter).
        if ($def->isKeyed() && $row['updated_at'] === null) {
            $row['updated_at'] = $change->at;
        }

        if ($change->isDelete() && $row['deleted_at'] === null) {
            $row['deleted_at'] = $change->at;
        }

        $row['row_version'] = $change->version;
        $row['synced_at'] = $context->now;
        $row['sync_seq'] = $change->seq > 0 ? $change->seq : null;
        $row['extra'] = $this->extra($def, $payload);

        $scopeProblem = $this->applyScope($def, $row, $change, $context);

        if ($scopeProblem !== null) {
            return $scopeProblem;
        }

        $parentId = $def->parent === null ? null : $row[$def->parent['column']];

        return new MappedChange($change, $def, $row, is_string($parentId) && $parentId !== '' ? $parentId : null, $unknownEnums);
    }

    /**
     * Branch and register columns: empty means none; a value must be the sending branch / one of its tills.
     * Sender-scoped rows (branch-owned, no branchId) get the sending branch. Child rows are finished by
     * ParentResolver, which needs the database.
     *
     * @param  array<string, mixed>  $row
     */
    private function applyScope(EntityDefinition $def, array &$row, SyncChange $change, SyncContext $context): ?Rejection
    {
        if ($def->scope === 'company' && ! $def->tenancy) {
            return null;
        }

        foreach ($def->scopeColumns as $column) {
            if ($row[$column] === '') {
                $row[$column] = null;
            }
        }

        if ($def->scope === 'sender') {
            $row['branch_id'] = $context->branchId;

            return null;
        }

        if ($def->hasScopeColumn('branch_id') && $row['branch_id'] !== null && $row['branch_id'] !== $context->branchId) {
            return Rejection::for($change, 'sync.wrong_branch', "The {$def->entity} row belongs to another branch than the one sending it.");
        }

        if ($def->hasScopeColumn('register_id') && $row['register_id'] !== null && ! $context->ownsRegister($row['register_id'])) {
            return Rejection::for($change, 'sync.unknown_register', "The {$def->entity} row names a till that is not in the sending branch.");
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extra(EntityDefinition $def, array $payload): ?string
    {
        $skip = $this->skip[$def->entity] ??= array_fill_keys([
            ...array_keys($def->fields),
            ...FieldTyper::BASE_FIELDS,
            ...$def->derived,
            ...$def->dropped,
            ...self::ALWAYS_DERIVED,
        ], true);

        $extra = array_diff_key($payload, $skip);

        // A member the till adds later that holds a secret (a key, token, password…) is never stored as sent.
        return $extra === [] ? null : (string) json_encode(Redactor::redact($extra), self::JSON_FLAGS);
    }

    /**
     * A secret (the till's licence key) is never stored: HMAC-SHA256 under APP_KEY, as module 1.3 hashes keys,
     * and the last 4 characters.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function secret(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [null, null];
        }

        $key = LicenceKey::tryParse($value);

        if ($key !== null) {
            return [$key->hash(), $key->last4()];
        }

        return [hash_hmac('sha256', $value, (string) config('app.key')), substr($value, -4)];
    }

    /**
     * A JSON number that needs no rounding, as a fixed-scale string ("1.45" → "1.45", 2 → "2.0000"); null when it
     * needs the full rules (rounding, exponent, range).
     */
    private static function fastDecimal(int|float $value, int $scale, int $digits): ?string
    {
        if ($value == 0) {
            return '0.'.str_repeat('0', $scale);
        }

        $string = (string) $value;

        if (str_contains($string, 'E')) {
            return null;
        }

        $dot = strpos($string, '.');
        $sign = $string[0] === '-' ? 1 : 0;
        $decimals = $dot === false ? 0 : strlen($string) - $dot - 1;

        if ($decimals > $scale || ($dot === false ? strlen($string) : $dot) - $sign > $digits) {
            return null;
        }

        return ($dot === false ? $string.'.' : $string).str_repeat('0', $scale - $decimals);
    }

    /** What a member the till did not send stands for in a non-nullable column (before Values::toColumn). */
    private static function absentDefault(string $type, SyncChange $change): mixed
    {
        return match ($type) {
            'int', 'bigint', 'money', 'cost', 'quantity', 'percent', 'rate' => 0,
            'bool' => false,
            'date' => substr($change->at, 0, 10),
            'time' => '00:00:00',
            'datetime' => str_replace(' ', 'T', $change->at).'Z',
            'json' => [],
            default => '',
        };
    }

    public static function fieldOf(EntityDefinition $def, string $column): ?FieldDefinition
    {
        foreach ($def->fields as $field) {
            if ($field->column === $column) {
                return $field;
            }
        }

        return null;
    }
}
