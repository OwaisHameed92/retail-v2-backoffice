<?php

namespace App\Domain\TillData\Registry;

use Illuminate\Database\Eloquent\Model;

/**
 * Typed view of one EntityRegistry entry, built once per entity per process.
 *
 * Scopes: company (company-wide), sender (branch-owned, no branchId: stored with the sending branch), branch,
 * register, child (branch/register come from the parent row, else the push header).
 */
final readonly class EntityDefinition
{
    /** Columns every till table has besides the payload fields. */
    public const META_COLUMNS = ['row_version', 'created_at', 'updated_at', 'deleted_at', 'synced_at', 'sync_seq', 'extra', 'portal_received_at'];

    /**
     * Hub-owned tables' pull bookkeeping the applier writes (docs/till-data.md, "Never echoed"). `hub_edited_at`
     * is only written by a portal edit, so it is not in the upsert.
     */
    public const HUB_COLUMNS = ['hub_version', 'hub_hash', 'origin_branch_id'];

    /** @var list<string> */
    public array $columns;

    /**
     * The field map flattened for the applier's hot loop: [field, column, type, nullable, max length, enum class].
     *
     * @var list<array{0: string, 1: string, 2: string, 3: bool, 4: int|null, 5: class-string<\BackedEnum>|null}>
     */
    public array $plan;

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $scopeColumns
     * @param  array{entity: string, column: string, table: string, scope: string}|null  $parent
     * @param  list<string>  $children
     * @param  list<string>  $derived
     * @param  array{when: array<string, list<string>>|null, whenParent: array<string, list<string>>|null, always: bool, mutable: list<string>}|null  $immutable
     * @param  array<string, string>  $tillFields
     * @param  array<string, FieldDefinition>  $fields
     * @param  list<string>  $dropped  secret members never stored (not even in `extra` or a conflict payload)
     */
    public function __construct(
        public string $entity,
        public string $model,
        public string $table,
        public string $ownership,
        public string $scope,
        public array $scopeColumns,
        public bool $tenancy,
        public ?array $parent,
        public array $children,
        public array $derived,
        public ?array $immutable,
        public array $tillFields,
        public array $fields,
        public array $dropped = [],
    ) {
        $columns = ['id', 'company_id', ...$scopeColumns];

        foreach ($fields as $field) {
            foreach ($field->columns() as $column) {
                if (! in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }

        $this->columns = [...$columns, ...self::META_COLUMNS, ...($ownership === 'hub' && ! $tenancy ? self::HUB_COLUMNS : [])];
        $this->plan = array_values(array_map(
            fn (FieldDefinition $f) => [$f->name, $f->column, $f->type, $f->nullable, $f->maxLength(), $f->enumClass()],
            $fields,
        ));
    }

    /**
     * @param  array<string, mixed>  $data  one EntityRegistry::ENTITIES entry
     */
    public static function fromArray(string $entity, array $data): self
    {
        $fields = [];

        foreach ($data['fields'] as $name => $field) {
            $fields[$name] = new FieldDefinition($name, $field['column'], $field['type'], $field['nullable'], $field['arg']);
        }

        return new self(
            $entity,
            $data['model'],
            $data['table'],
            $data['ownership'],
            $data['scope'],
            $data['scopeColumns'],
            $data['tenancy'],
            $data['parent'],
            $data['children'],
            $data['derived'],
            $data['immutable'],
            $data['tillFields'],
            $fields,
            $data['dropped'] ?? [],
        );
    }

    public function isHubOwned(): bool
    {
        return $this->ownership === 'hub';
    }

    public function hasScopeColumn(string $column): bool
    {
        return in_array($column, $this->scopeColumns, true);
    }

    public function isDerived(string $field): bool
    {
        return in_array($field, $this->derived, true);
    }
}
