<?php

namespace App\Domain\Sync\Support;

use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Sync\Data\PushReply;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * `Idempotency-Key` on `sync/push` (contract §17.11 rule 5; optional on push, which is idempotent by row anyway).
 * The reply to (branch, key) is kept for config('sync.push.idempotency_hours'): the same key and body again → the
 * same status and body; the same key with another body → 422 request.idempotency_mismatch. Looked up and stored
 * inside the branch's push lock, so a retry that arrives while the first is running waits for its reply.
 */
final class PushIdempotency
{
    public function __construct(private readonly Cache $cache) {}

    /**
     * @throws ApiException
     */
    public function find(string $branchId, ?string $key, string $fingerprint): ?PushReply
    {
        $stored = $key === null ? null : $this->cache->get($this->slot($branchId, $key));

        if (! is_array($stored)) {
            return null;
        }

        if (! hash_equals((string) ($stored['fingerprint'] ?? ''), $fingerprint)) {
            throw SyncApiErrors::idempotencyMismatch();
        }

        return new PushReply((int) $stored['status'], (array) $stored['body'], true);
    }

    public function remember(string $branchId, ?string $key, string $fingerprint, PushReply $reply): void
    {
        if ($key !== null) {
            $this->cache->put($this->slot($branchId, $key), [
                'fingerprint' => $fingerprint,
                'status' => $reply->status,
                'body' => $reply->body,
            ], now()->addHours((int) config('sync.push.idempotency_hours', 24)));
        }
    }

    private function slot(string $branchId, string $key): string
    {
        return 'sync-push:idem:'.hash('sha256', $branchId.'|'.strtoupper($key));
    }
}
