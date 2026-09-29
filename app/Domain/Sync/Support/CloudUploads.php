<?php

namespace App\Domain\Sync\Support;

use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Sync\Data\SyncCaller;
use App\Domain\Sync\Enums\CloudUploadStatus;
use App\Domain\Sync\Models\CloudUpload;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The history upload of a shop moving to the cloud (contract v1.4.1 §17.8): which uploads a branch may push to, and
 * what has arrived. The rows of an upload are the ledger's `stream` = upload id (sync_applied_changes, keyed by
 * (uploadId, seq)), so counts are exact whatever was retried.
 */
final class CloudUploads
{
    /**
     * `sync/push` in initial mode: the upload must be this branch's and still open.
     *
     * @throws ApiException 404 migrate.upload_not_found, 409 migrate.upload_closed
     */
    public static function forPush(SyncCaller $caller, string $uploadId): CloudUpload
    {
        $upload = CloudUpload::withoutCompanyScope()->where('branch_id', $caller->branch->id)->whereKey($uploadId)->first()
            ?? throw MigrationErrors::uploadNotFound();

        if (! $upload->isOpen()) {
            throw MigrationErrors::uploadClosed();
        }

        return $upload;
    }

    /** After an initial batch: what the upload now holds (for `resumeFromSeq` and the admin's progress bar). */
    public static function refresh(CloudUpload $upload): void
    {
        $received = self::rows($upload)->count();

        CloudUpload::withoutCompanyScope()->whereKey($upload->id)->update([
            'received_rows' => $received,
            'acknowledged_seq' => self::contiguousSeq($upload, $received),
            'last_batch_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }

    /**
     * Rows held per entity.
     *
     * @return array<string, int>
     */
    public static function countsByEntity(CloudUpload $upload): array
    {
        return self::rows($upload)->groupBy('entity')->selectRaw('entity, count(*) as n')->pluck('n', 'entity')
            ->map(fn ($n) => (int) $n)->all();
    }

    /** The highest upload seq held with every seq from 1 up to it (0 when seq 1 is missing). */
    public static function contiguousSeq(CloudUpload $upload, ?int $received = null): int
    {
        $received ??= self::rows($upload)->count();
        $max = (int) self::rows($upload)->max('seq');

        if ($received === 0 || ! self::rows($upload)->where('seq', 1)->exists()) {
            return 0;
        }

        if ($received >= $max) {
            return $max; // seqs are unique per stream: no gap
        }

        $gap = self::rows($upload)->from('sync_applied_changes as a')
            ->whereNotExists(fn (Builder $b) => $b->selectRaw('1')->from('sync_applied_changes as b')
                ->whereColumn('b.company_id', 'a.company_id')->whereColumn('b.branch_id', 'a.branch_id')
                ->whereColumn('b.stream', 'a.stream')->whereRaw('b.seq = a.seq + 1'))
            ->min('a.seq');

        return (int) $gap;
    }

    public static function status(CloudUpload $upload, bool $complete): CloudUploadStatus
    {
        return $complete || ! $upload->isOpen() ? CloudUploadStatus::Complete : CloudUploadStatus::Open;
    }

    private static function rows(CloudUpload $upload): Builder
    {
        return DB::table('sync_applied_changes')
            ->where('company_id', $upload->company_id)
            ->where('branch_id', $upload->branch_id)
            ->where('stream', $upload->id);
    }
}
