<?php

namespace App\Domain\TillData\Sync\Data;

use App\Domain\TillData\Sync\Enums\ChangeOutcome;

/**
 * Result of ApplySyncChanges. `acknowledgedSeq` is the highest seq with every lower seq of the batch accepted
 * (contract section 7): the first rejected change stops it. `accepted` counts every accepted change, including
 * stale, unchanged, duplicate, conflict and skipped ones. A retried batch gives the same numbers. `receivedAt`
 * (required in the v1.4 push reply) is when the batch was durably stored (UTC, ISO-8601 Z, after the commit); a
 * retry gets the first time (ChangeLedger::receivedAt). Each row keeps its first arrival in `portal_received_at`.
 */
final readonly class ApplyResult
{
    /**
     * @param  list<Rejection>  $rejected  in seq order
     * @param  array<string, int>  $outcomes  ChangeOutcome value => count
     */
    public function __construct(
        public int $acknowledgedSeq,
        public int $accepted,
        public array $rejected,
        public array $outcomes,
        public float $durationMs,
        public string $receivedAt = '',
    ) {}

    public function count(ChangeOutcome $outcome): int
    {
        return $this->outcomes[$outcome->value] ?? 0;
    }

    public function firstRejection(): ?Rejection
    {
        return $this->rejected[0] ?? null;
    }

    /**
     * The push reply body (schemas/push-reply.schema.json).
     *
     * @return array{acknowledgedSeq: int, accepted: int, receivedAt: string}
     */
    public function toPushReply(): array
    {
        return ['acknowledgedSeq' => $this->acknowledgedSeq, 'accepted' => $this->accepted, 'receivedAt' => $this->receivedAt];
    }
}
