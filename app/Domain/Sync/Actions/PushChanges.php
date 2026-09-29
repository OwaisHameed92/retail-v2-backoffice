<?php

namespace App\Domain\Sync\Actions;

use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Sync\Data\PushInput;
use App\Domain\Sync\Data\PushReply;
use App\Domain\Sync\Data\SyncCaller;
use App\Domain\Sync\Support\CloudUploads;
use App\Domain\Sync\Support\PushBody;
use App\Domain\Sync\Support\PushIdempotency;
use App\Domain\Sync\Support\SyncApiErrors;
use App\Domain\Sync\Support\SyncStatusRecorder;
use App\Domain\TillData\Actions\ApplySyncChanges;
use App\Domain\TillData\Sync\Data\ApplyResult;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * `POST /api/v1/sync/push` (module 2.2, contract v1.4.1 §3, §7, §9, §17.8, §19): one branch's batch of changed rows.
 *
 * 1. Body: gzip or plain JSON, ≤ 5,000 rows and 50 MB (PushBody; else 413 / 400).
 * 2. Mode: delta (default; seq = the branch's ChangeLog) or `initial` with `X-SSPOS-Upload-Id` (seq = the upload's
 *    own 1…N, kept apart in the ledger). Module 2.8: the upload must be this branch's (404 migrate.upload_not_found)
 *    and still open (409 migrate.upload_closed); after each batch its progress is refreshed (CloudUploads).
 * 3. Per-branch lock: two pushes of one branch never interleave; a second one waits up to
 *    config('sync.push.lock_wait_seconds'), then 503 server.busy with retryAfterSeconds. The same Idempotency-Key
 *    while its first request still runs → 409 request.in_progress (§21.7).
 * 4. Idempotency-Key replay (PushIdempotency), then ApplySyncChanges as the key's branch (it translates the till's
 *    ids through id_map).
 * 5. Reply 200 `{acknowledgedSeq, accepted}`; when the first row of the batch is rejected (nothing can be
 *    acknowledged) 422 row.invalid with its `rejectedKey`. Rows after a rejection are stored anyway and come back as
 *    duplicates. `sync_branch_status` is updated either way.
 */
final class PushChanges
{
    public function __construct(
        private readonly ApplySyncChanges $apply,
        private readonly PushIdempotency $idempotency,
        private readonly SyncStatusRecorder $status,
    ) {}

    /**
     * @throws ApiException
     */
    public function handle(SyncCaller $caller, PushInput $input): PushReply
    {
        try {
            $stream = $this->stream($input);
            $upload = $stream === '' ? null : CloudUploads::forPush($caller, $stream);
            $idempotencyKey = $this->idempotencyKey($input->idempotencyKey);
            self::raiseMemoryLimit();
            ['changes' => $changes, 'fingerprint' => $fingerprint] = PushBody::decode($input->body, $input->encoding);

            return $this->locked($caller, $idempotencyKey, function () use ($caller, $input, $stream, $upload, $idempotencyKey, $changes, $fingerprint) {
                $fingerprint = hash('sha256', $stream.'|'.$fingerprint);

                if (($replay = $this->idempotency->find($caller->branch->id, $idempotencyKey, $fingerprint)) !== null) {
                    return $replay;
                }

                $result = $this->apply->handle($caller->company, $caller->branch, $changes, $stream);
                $this->status->pushed($caller, $stream, $result, $input->appVersion, $input->tillRegisterId);

                if ($upload !== null) {
                    CloudUploads::refresh($upload);
                }

                $reply = $this->reply($result, $changes, $input->traceId);
                $this->idempotency->remember($caller->branch->id, $idempotencyKey, $fingerprint, $reply);
                $this->log($caller, $result, $stream);

                return $reply;
            });
        } catch (ApiException $e) {
            $this->status->failed($caller, $e, $input->appVersion, $input->tillRegisterId);

            throw $e;
        }
    }

    /**
     * The branch lock, and a marker for the Idempotency-Key: a repeat of a request still running gets 409
     * request.in_progress (contract §17.11 rule 5, §21.7); another push of the branch waits, then 503 server.busy.
     *
     * @param  callable(): PushReply  $callback
     *
     * @throws ApiException
     */
    private function locked(SyncCaller $caller, ?string $idempotencyKey, callable $callback): PushReply
    {
        $seconds = max(10, (int) config('sync.push.lock_seconds', 120));
        $running = $idempotencyKey === null ? null : 'sync-push:running:'.$caller->branch->id.':'.strtoupper($idempotencyKey);

        if ($running !== null && ! Cache::add($running, true, $seconds)) {
            throw SyncApiErrors::inProgress();
        }

        try {
            $lock = Cache::lock('sync-push:branch:'.$caller->branch->id, $seconds);
            $wait = max(0, (int) config('sync.push.lock_wait_seconds', 10));

            try {
                $acquired = $wait > 0 ? $lock->block($wait) : $lock->get();
            } catch (LockTimeoutException) {
                $acquired = false;
            }

            if (! $acquired) {
                throw SyncApiErrors::busy((int) config('sync.push.busy_retry_after', 5));
            }

            try {
                return $callback();
            } finally {
                $lock->release();
            }
        } finally {
            if ($running !== null) {
                Cache::forget($running);
            }
        }
    }

    /**
     * @param  list<mixed>  $changes
     */
    private function reply(ApplyResult $result, array $changes, string $traceId): PushReply
    {
        $first = $result->firstRejection();
        $lowest = self::lowestSeq($changes);

        if ($first === null || ($lowest !== null && $result->acknowledgedSeq >= $lowest)) {
            return new PushReply(200, $result->toPushReply());
        }

        $what = $first->entity !== null && $first->entityId !== null ? "{$first->entity} {$first->entityId}" : 'A change';
        $error = SyncApiErrors::rowInvalid($first->key, "{$what} could not be stored: {$first->message}");

        return new PushReply(422, [
            'code' => $error->errorCode,
            'message' => $error->getMessage(),
            'traceId' => $traceId,
            'retryAfterSeconds' => null,
            'rejectedKey' => $error->rejectedKey,
        ]);
    }

    /**
     * @param  list<mixed>  $changes
     */
    private static function lowestSeq(array $changes): ?int
    {
        $seqs = [];

        foreach ($changes as $change) {
            $seq = is_array($change) ? ($change['seq'] ?? null) : null;

            if (is_int($seq) || (is_string($seq) && ctype_digit($seq))) {
                $seqs[] = (int) $seq;
            }
        }

        return $seqs === [] ? null : min($seqs);
    }

    /**
     * @throws ApiException
     */
    private function stream(PushInput $input): string
    {
        $mode = strtolower(trim((string) $input->mode));
        $upload = strtoupper(trim((string) $input->uploadId));

        return match ($mode) {
            '', 'delta' => '',
            'initial' => Ulid::isValid($upload) ? $upload : throw SyncApiErrors::invalid('An initial upload needs X-SSPOS-Upload-Id (the uploadId from cloud/migrate).'),
            default => throw SyncApiErrors::invalid('X-SSPOS-Sync-Mode must be delta or initial.'),
        };
    }

    /**
     * @throws ApiException
     */
    private function idempotencyKey(?string $key): ?string
    {
        $key = trim((string) $key);

        if ($key === '') {
            return null;
        }

        if (preg_match('/^([0-9A-HJKMNP-TV-Z]{26}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i', $key) !== 1) {
            throw SyncApiErrors::invalid('The Idempotency-Key must be a ULID or a UUID.');
        }

        return $key;
    }

    private function log(SyncCaller $caller, ApplyResult $result, string $stream): void
    {
        if (($first = $result->firstRejection()) !== null) {
            Log::warning('Till push: rows rejected.', [
                'company_id' => $caller->company->id,
                'branch_id' => $caller->branch->id,
                'upload_id' => $stream === '' ? null : $stream,
                'rejected' => count($result->rejected),
                'rejectedKey' => $first->key,
                'code' => $first->code,
                'acknowledgedSeq' => $result->acknowledgedSeq,
            ]);
        }
    }

    /** A 50 MB JSON body decodes to several hundred MB of PHP arrays; never lower a higher limit. */
    private static function raiseMemoryLimit(): void
    {
        $wanted = (string) config('sync.push.memory_limit', '1024M');
        $current = (string) ini_get('memory_limit');

        if ($current !== '-1' && self::bytes($current) < self::bytes($wanted)) {
            @ini_set('memory_limit', $wanted);
        }
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
