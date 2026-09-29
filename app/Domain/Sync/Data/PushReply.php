<?php

namespace App\Domain\Sync\Data;

/**
 * What `sync/push` answers: 200 `{acknowledgedSeq, accepted}` (push-reply schema) or 422 row.invalid (error-reply
 * schema) when the first row cannot be stored. `replayed` = the stored reply of an earlier request with the same
 * Idempotency-Key.
 */
final readonly class PushReply
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public int $status,
        public array $body,
        public bool $replayed = false,
    ) {}
}
