<?php

namespace App\Domain\TillData\Sync\Data;

/**
 * One validated envelope (schemas/sync-change.schema.json). `at` is normalised to UTC "Y-m-d H:i:s".
 * `baseVersion` (contract §19.3, announced for v1.4, push only, hub-owned rows): the portal version the till last
 * applied for this row; null while tills do not send it.
 */
final readonly class SyncChange
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function __construct(
        public int $index,
        public int $seq,
        public string $entity,
        public string $entityId,
        public string $op,
        public int $version,
        public string $companyId,
        public string $branchId,
        public string $registerId,
        public string $at,
        public ?array $payload,
        public string $key,
        public ?int $baseVersion = null,
    ) {}

    public function isDelete(): bool
    {
        return $this->op === 'D';
    }
}
