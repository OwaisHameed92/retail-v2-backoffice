<?php

namespace App\Domain\TillData\Generator;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns one JSON Schema property into a FieldSpec (or null when the member is derived and not stored).
 * Rules are documented in docs/till-data.md ("Column rules").
 */
final class FieldTyper
{
    /** Members every entity has; the store keeps them in fixed columns (id, company_id, timestamps, row_version). */
    public const BASE_FIELDS = ['id', 'companyId', 'createdAt', 'updatedAt', 'rowVersion', 'deletedAt'];

    public const ULID_LENGTH = 26;

    public const ID_LENGTH = 64;

    public const STRING_LENGTH = 255;

    public const ENUM_LENGTH = 40;

    /**
     * @param  array<string, mixed>  $definitions  definitions.php
     * @param  array<string, list<string>>  $knownEnums  samples/enums.json (name => values)
     */
    public function __construct(private readonly array $definitions, private readonly array $knownEnums) {}

    /**
     * @param  array<string, mixed>  $property
     * @param  array<string, mixed>  $entityDef
     * @param  list<string>  $scopeColumns  scope columns the table has (branch_id/register_id)
     */
    public function type(string $entity, string $name, array $property, array $entityDef, array $scopeColumns): ?FieldSpec
    {
        $types = (array) ($property['type'] ?? []);
        $nullable = in_array('null', $types, true);
        $column = $entityDef['columns'][$name] ?? Str::snake($name);

        if (isset($property['enum'])) {
            $values = array_values(array_filter($property['enum'], fn ($v) => $v !== null));
            $nullable = $nullable || in_array(null, $property['enum'], true);

            return new FieldSpec($name, $column, 'enum', $nullable, $this->enumName($entity, $name, $values), $values);
        }

        if (in_array('object', $types, true) || in_array('array', $types, true)) {
            return in_array($name, $entityDef['json'] ?? [], true)
                ? new FieldSpec($name, $column, 'json', $nullable)
                : null; // Money objects and navigation collections: derived.
        }

        if (in_array('number', $types, true)) {
            return new FieldSpec($name, $column, $this->decimalKind($entity, $name), $nullable);
        }

        if (in_array('integer', $types, true)) {
            $big = in_array($name, $this->definitions['bigIntegers'] ?? [], true);

            return new FieldSpec($name, $column, $big ? 'bigint' : 'int', $nullable);
        }

        if (in_array('boolean', $types, true)) {
            return new FieldSpec($name, $column, 'bool', $nullable);
        }

        if (! in_array('string', $types, true)) {
            throw new RuntimeException("Unsupported schema type for {$entity}.{$name}: ".json_encode($property));
        }

        return match ($property['format'] ?? null) {
            'date' => new FieldSpec($name, $column, 'date', $nullable),
            'date-time' => new FieldSpec($name, $column, 'datetime', $nullable),
            'time' => new FieldSpec($name, $column, 'time', $nullable),
            default => $this->stringField($entity, $name, $column, $nullable, $entityDef, $scopeColumns),
        };
    }

    /**
     * @param  array<string, mixed>  $entityDef
     * @param  list<string>  $scopeColumns
     */
    private function stringField(string $entity, string $name, string $column, bool $nullable, array $entityDef, array $scopeColumns): FieldSpec
    {
        if (in_array($name, $entityDef['secret'] ?? [], true)) {
            return new FieldSpec($name, $column, 'secret', $nullable);
        }

        if (str_ends_with($name, 'Json') || in_array($name, $this->definitions['longText'] ?? [], true)) {
            return new FieldSpec($name, $column, 'longText', $nullable);
        }

        if (in_array($name, $this->definitions['text'] ?? [], true) || in_array("{$entity}.{$name}", $this->definitions['text'] ?? [], true)) {
            return new FieldSpec($name, $column, 'text', $nullable);
        }

        $length = match (true) {
            in_array($column, $scopeColumns, true) => self::ULID_LENGTH,
            str_ends_with($name, 'Id') => self::ID_LENGTH,
            default => self::STRING_LENGTH,
        };

        return new FieldSpec($name, $column, 'string', $nullable, $length);
    }

    private function decimalKind(string $entity, string $name): string
    {
        $decimals = $this->definitions['decimals'] ?? [];

        foreach ($decimals as $kind => $names) {
            if (in_array("{$entity}.{$name}", $names, true)) {
                return $kind;
            }
        }

        foreach ($decimals as $kind => $names) {
            if (in_array($name, $names, true)) {
                return $kind;
            }
        }

        return 'money';
    }

    /**
     * @param  list<string>  $values
     */
    private function enumName(string $entity, string $name, array $values): string
    {
        $override = $this->definitions['enumNames']["{$entity}.{$name}"] ?? null;

        if (is_string($override)) {
            return $override;
        }

        foreach ($this->knownEnums as $enum => $known) {
            if ($known === $values) {
                return $enum;
            }
        }

        return $entity.Str::studly($name);
    }
}
