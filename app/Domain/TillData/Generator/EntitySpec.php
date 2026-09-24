<?php

namespace App\Domain\TillData\Generator;

/**
 * Everything the generator knows about one till entity: schema fields plus the overrides from definitions.php.
 *
 * Scopes: company (company-wide), sender (branch-owned but no branchId: stored with the sending branch),
 * branch, register, child (branch/register come from the parent row or the push header).
 */
final readonly class EntitySpec
{
    /**
     * @param  list<FieldSpec>  $fields
     * @param  list<string>  $derived
     * @param  list<list<string>>  $indexes
     * @param  array{when: array<string, list<string>>|null, whenParent: array<string, list<string>>|null, always: bool, mutable: list<string>}|null  $immutable
     * @param  list<class-string>  $traits
     * @param  list<string>  $hidden
     * @param  array<string, string>  $tillFields  Tenancy entities: till field => portal column.
     */
    public function __construct(
        public string $name,
        public string $class,
        public string $table,
        public string $ownership,
        public string $scope,
        public ?string $parentEntity,
        public ?string $parentField,
        public ?string $group,
        public array $fields,
        public array $derived,
        public array $indexes,
        public ?array $immutable,
        public array $traits,
        public array $hidden,
        public bool $tenancy,
        public ?string $tenancyModel,
        public array $tillFields,
        public ?string $parentScope = null,
    ) {}

    public function field(string $name): ?FieldSpec
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    public function hasColumn(string $column): bool
    {
        foreach ($this->fields as $field) {
            if ($field->column === $column) {
                return true;
            }
        }

        return in_array($column, $this->scopeColumns(), true);
    }

    /**
     * Denormalised scope columns this table gets whether or not the payload has the field.
     *
     * @return list<string>
     */
    public function scopeColumns(): array
    {
        return match ($this->scope) {
            'register' => ['branch_id', 'register_id'],
            'branch', 'sender' => ['branch_id'],
            'child' => $this->childScopeColumns(),
            default => [],
        };
    }

    public function parentColumn(): ?string
    {
        return $this->parentField === null ? null : $this->field($this->parentField)?->column;
    }

    public function isHubOwned(): bool
    {
        return $this->ownership === 'hub';
    }

    /**
     * @return list<string>
     */
    private function childScopeColumns(): array
    {
        $columns = ['branch_id'];

        if ($this->field('registerId') !== null || $this->parentScope === 'register') {
            $columns[] = 'register_id';
        }

        return $columns;
    }

    public function withParentScope(string $parentScope): self
    {
        return new self(
            $this->name, $this->class, $this->table, $this->ownership, $this->scope, $this->parentEntity,
            $this->parentField, $this->group, $this->fields, $this->derived, $this->indexes, $this->immutable,
            $this->traits, $this->hidden, $this->tenancy, $this->tenancyModel, $this->tillFields, $parentScope,
        );
    }
}
