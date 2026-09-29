<?php

namespace App\Domain\TillData\Generator;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Reads the till contract (schemas/entities, samples/ownership.json, samples/enums.json) and definitions.php and
 * builds one EntitySpec per schema entity, sorted by name. Throws on anything inconsistent so a bad override
 * fails the generator instead of producing a silently wrong store.
 */
final class SchemaCatalog
{
    /** @var array<string, mixed> */
    private array $definitions;

    private string $contractPath;

    /** @var array<string, EntitySpec>|null */
    private ?array $entities = null;

    public function __construct(private readonly string $basePath, ?string $definitionsFile = null)
    {
        $this->definitions = require $definitionsFile ?? $basePath.'/app/Domain/TillData/definitions.php';
        $this->contractPath = $basePath.'/'.$this->definitions['contract'];
    }

    /**
     * @return array<string, mixed>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function contract(): string
    {
        return $this->definitions['contract'];
    }

    /**
     * @return array<string, string> entity => hub|branch, exactly as samples/ownership.json
     */
    public function ownership(): array
    {
        return $this->readJson('samples/ownership.json')['entities'];
    }

    /**
     * Entities the till never syncs (`local` in samples/ownership.json, contract §10): no table, no model.
     *
     * @return list<string>
     */
    public function localEntities(): array
    {
        $local = array_keys(array_filter($this->ownership(), fn (string $owner) => $owner === 'local'));
        sort($local);

        return $local;
    }

    /**
     * @return array<string, list<string>> enum name => values, exactly as samples/enums.json
     */
    public function knownEnums(): array
    {
        return $this->readJson('samples/enums.json');
    }

    /**
     * @return array<string, EntitySpec>
     */
    public function entities(): array
    {
        if ($this->entities !== null) {
            return $this->entities;
        }

        $typer = new FieldTyper($this->definitions, $this->knownEnums());
        $ownership = $this->ownership();
        $specs = [];

        foreach (glob($this->contractPath.'/schemas/entities/*.schema.json') ?: [] as $file) {
            $name = basename($file, '.schema.json');

            if (($ownership[$name] ?? null) === 'local') {
                continue;
            }
            $specs[$name] = $this->buildEntity($name, $this->readJson('schemas/entities/'.basename($file)), $ownership, $typer);
        }

        ksort($specs);
        $this->assertDefinitionsMatch($specs);

        foreach ($specs as $name => $spec) {
            if ($spec->parentEntity !== null) {
                $parent = $specs[$spec->parentEntity] ?? throw new RuntimeException("{$name}: unknown parent {$spec->parentEntity}.");
                $specs[$name] = $spec->withParentScope($parent->scope);
            }
        }

        return $this->entities = $specs;
    }

    /**
     * Enum class name => values, sorted by name. Throws when two fields share a name but not the values.
     *
     * @return array<string, array{values: list<string>, fields: list<string>}>
     */
    public function enums(): array
    {
        $enums = [];

        foreach ($this->entities() as $entity) {
            foreach ($entity->fields as $field) {
                if ($field->type !== 'enum') {
                    continue;
                }

                $name = (string) $field->arg;

                if (isset($enums[$name]) && $enums[$name]['values'] !== $field->enumValues) {
                    throw new RuntimeException("Enum {$name} is used with different values by {$entity->name}.{$field->name}. Add an enumNames override.");
                }

                $enums[$name]['values'] = $field->enumValues;
                $enums[$name]['fields'][] = "{$entity->name}.{$field->name}";
            }
        }

        ksort($enums);

        return $enums;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, string>  $ownership
     */
    private function buildEntity(string $name, array $schema, array $ownership, FieldTyper $typer): EntitySpec
    {
        $def = $this->definitions['entities'][$name] ?? [];
        $tenancy = $this->definitions['tenancy'][$name] ?? null;
        $properties = $schema['properties'];
        $parent = $def['parent'] ?? null;
        $owner = $ownership[$name] ?? throw new RuntimeException("{$name} is missing from samples/ownership.json.");
        $scope = $def['scope'] ?? $this->inferScope($properties, $parent !== null, $owner);
        $scopeColumns = match ($scope) {
            'register' => ['branch_id', 'register_id'],
            'branch', 'sender' => ['branch_id'],
            'child' => ['branch_id', 'register_id'],
            default => [],
        };

        $keyedBy = $def['keyedBy'] ?? null;
        $fields = [];
        $derived = [];
        $dropped = [];

        foreach ($properties as $field => $property) {
            if (in_array($field, FieldTyper::BASE_FIELDS, true)) {
                continue;
            }

            if (in_array($field, $def['drop'] ?? [], true)) {
                $dropped[] = $field;

                continue;
            }

            $keyField = in_array($field, $keyedBy ?? [], true);

            if (! $keyField && (in_array($field, $this->definitions['derived'], true) || in_array($field, $def['derived'] ?? [], true))) {
                $derived[] = $field;

                continue;
            }

            $spec = $typer->type($name, $field, $property, $def, $scopeColumns);
            $spec === null ? $derived[] = $field : $fields[] = $spec;
        }

        $columnOf = function (string $field) use ($name, $fields): string {
            foreach ($fields as $spec) {
                if ($spec->name === $field) {
                    return $spec->column;
                }
            }

            throw new RuntimeException("{$name}: unknown field {$field} in definitions.php.");
        };

        $immutable = null;

        if (isset($def['immutable'])) {
            $immutable = [
                'when' => $this->conditionColumns($def['immutable']['when'] ?? null, $columnOf),
                'whenParent' => $def['immutable']['whenParent'] ?? null,
                'always' => (bool) ($def['immutable']['always'] ?? false),
                'mutable' => array_map($columnOf, $def['immutable']['mutable'] ?? []),
            ];
        }

        return new EntitySpec(
            name: $name,
            class: $tenancy !== null ? class_basename($tenancy['model']) : ($def['class'] ?? $name),
            table: $tenancy['table'] ?? $def['table'] ?? Str::snake(Str::pluralStudly($name)),
            ownership: $owner,
            scope: $tenancy !== null ? match ($name) {
                'Branch' => 'branch',
                'Register' => 'register',
                default => 'company',
            } : $scope,
            parentEntity: $parent[0] ?? null,
            parentField: $parent[1] ?? null,
            group: $this->groupOf($name),
            fields: $fields,
            derived: $derived,
            indexes: $def['indexes'] ?? [],
            immutable: $immutable,
            traits: $def['traits'] ?? [],
            hidden: array_values(array_map($columnOf, $def['hidden'] ?? [])),
            tenancy: $tenancy !== null,
            tenancyModel: $tenancy['model'] ?? null,
            tillFields: $tenancy['tillFields'] ?? [],
            // Members the contract removed (e.g. User.remoteApprovalSecret in v1.4) stay dropped if an older till sends them.
            dropped: array_values(array_unique([...$dropped, ...($def['drop'] ?? [])])),
            keyedBy: $keyedBy,
        );
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function inferScope(array $properties, bool $hasParent, string $owner): string
    {
        $isRequiredString = fn (string $field) => isset($properties[$field]) && (array) ($properties[$field]['type'] ?? []) === ['string'];

        return match (true) {
            $hasParent => 'child',
            $isRequiredString('branchId') && $isRequiredString('registerId') => 'register',
            $isRequiredString('branchId') => 'branch',
            $owner === 'branch' => 'sender',
            default => 'company',
        };
    }

    /**
     * @param  array<string, list<string>>|null  $condition
     * @param  callable(string): string  $columnOf
     * @return array<string, list<string>>|null
     */
    private function conditionColumns(?array $condition, callable $columnOf): ?array
    {
        if ($condition === null) {
            return null;
        }

        $columns = [];

        foreach ($condition as $field => $values) {
            $columns[$columnOf($field)] = $values;
        }

        return $columns;
    }

    private function groupOf(string $entity): ?string
    {
        if (isset($this->definitions['tenancy'][$entity])) {
            return null;
        }

        $found = [];

        foreach ($this->definitions['groups'] as $group => $entities) {
            if (in_array($entity, $entities, true)) {
                $found[] = $group;
            }
        }

        if (count($found) !== 1) {
            throw new RuntimeException("{$entity} must be in exactly one group in definitions.php (found ".count($found).').');
        }

        return $found[0];
    }

    /**
     * @param  array<string, EntitySpec>  $specs
     */
    private function assertDefinitionsMatch(array $specs): void
    {
        $named = array_merge(
            array_keys($this->definitions['entities']),
            array_keys($this->definitions['tenancy']),
            ...array_values($this->definitions['groups']),
        );

        foreach ($named as $entity) {
            if (! isset($specs[$entity])) {
                throw new RuntimeException("definitions.php names {$entity}, which has no schema.");
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $relative): array
    {
        $path = $this->contractPath.'/'.$relative;
        $json = file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
