<?php

namespace App\Domain\TillData\Sync\Data;

/**
 * A change the store refused. The till keeps it and sends it again; the push reply acknowledges only the rows
 * before the first rejection. `key` is the change's `entity:entityId:version` (the error body's rejectedKey).
 *
 * Codes: change.invalid, entity.unknown, sync.wrong_company, sync.wrong_branch, sync.unknown_register,
 * sync.duplicate_seq, sync.parent_rejected, payload.missing, payload.id_mismatch, payload.invalid, entity.id_taken,
 * entity.not_found, store.failed.
 */
final readonly class Rejection
{
    public function __construct(
        public int $index,
        public ?int $seq,
        public string $key,
        public string $code,
        public string $message,
        public ?string $entity = null,
        public ?string $entityId = null,
    ) {}

    public static function for(SyncChange $change, string $code, string $message): self
    {
        return new self($change->index, $change->seq, $change->key, $code, $message, $change->entity, $change->entityId);
    }

    /**
     * @return array{key: string, seq: int|null, code: string, message: string}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'seq' => $this->seq, 'code' => $this->code, 'message' => $this->message];
    }
}
