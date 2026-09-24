<?php

namespace App\Domain\TillData\Sync\Data;

/**
 * One validated envelope (schemas/sync-change.schema.json). `at` is normalised to UTC "Y-m-d H:i:s".
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
    ) {}

    public function isDelete(): bool
    {
        return $this->op === 'D';
    }
}
