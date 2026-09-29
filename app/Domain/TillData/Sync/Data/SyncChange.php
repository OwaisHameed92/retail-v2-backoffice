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

    /**
     * The same change stored under another id (a keyed row's id derived from its payload, contract §10.3).
     *
     * @param  array<string, mixed>|null  $payload  a normalised payload, else the same one
     */
    public function withEntityId(string $entityId, ?array $payload = null): self
    {
        return new self(
            $this->index, $this->seq, $this->entity, $entityId, $this->op, $this->version, $this->companyId,
            $this->branchId, $this->registerId, $this->at, $payload ?? $this->payload, $this->key, $this->baseVersion,
        );
    }

    public function isDelete(): bool
    {
        return $this->op === 'D';
    }
}
