<?php

namespace App\Domain\TillData\Sync\Data;

use App\Domain\TillData\Registry\EntityDefinition;

/**
 * A change whose payload passed validation, mapped to a table row (every column of the entity, in order).
 * `row` is null for a delete sent without a payload. `parentFrozen` is set by ParentResolver.
 */
final class MappedChange
{
    /**
     * @param  array<string, mixed>|null  $row
     * @param  list<string>  $unknownEnums  "Entity.field=value" stored as sent
     */
    public function __construct(
        public readonly SyncChange $change,
        public readonly EntityDefinition $definition,
        public ?array $row,
        public readonly ?string $parentId = null,
        public readonly array $unknownEnums = [],
        public bool $parentFrozen = false,
    ) {}
}
