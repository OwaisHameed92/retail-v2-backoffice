<?php

namespace App\Domain\Sync\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\ApiDate;
use App\Domain\Sync\Data\SyncCaller;
use App\Domain\Sync\Enums\CloudUploadStatus;
use App\Domain\Sync\Models\CloudUpload;
use App\Domain\Sync\Support\CloudUploads;
use App\Domain\Sync\Support\MigrationErrors;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `POST /api/v1/cloud/migrate/complete` (module 2.8, contract v1.4.1 §17.8, migrate-complete-*.schema.json): the till
 * sent its last history batch. We count what arrived (the upload's ledger rows, per entity) against the till's counts.
 *
 * - Everything there (every entity's count, `totalRows`, and seqs 1…`highestSeq` without a gap) → `complete`: the
 *   upload closes (a later initial batch gets 409 migrate.upload_closed), the branch's push cursor becomes
 *   `snapshotChangeLogSeq` (`pushResumesAfterSeq`); the till then pushes normally from there and pulls from 0.
 * - Otherwise `incomplete`, with `missing[]` and `resumeFromSeq` (the till resends after it and calls again).
 * - Idempotent per upload: a complete upload answers `complete` again. Unknown upload (or another shop's) → 404
 *   migrate.upload_not_found; another PC than the one moving the shop → 403 device.not_main_till.
 */
class CompleteCloudMigration
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @param  array<string, int>  $rowCounts
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function handle(SyncCaller $caller, ?string $installId, string $uploadId, int $totalRows, int $highestSeq, array $rowCounts, int $snapshotSeq): array
    {
        $now = CarbonImmutable::now()->startOfSecond();

        return DB::transaction(function () use ($caller, $installId, $uploadId, $totalRows, $highestSeq, $rowCounts, $snapshotSeq, $now) {
            $upload = CloudUpload::withoutCompanyScope()->where('branch_id', $caller->branch->id)->whereKey($uploadId)->lockForUpdate()->first()
                ?? throw MigrationErrors::uploadNotFound();

            if ($installId !== null && $installId !== '' && strtoupper($installId) !== $upload->install_id) {
                throw MigrationErrors::notMainTill();
            }

            $held = CloudUploads::countsByEntity($upload);
            $received = array_sum($held);
            $contiguous = CloudUploads::contiguousSeq($upload, $received);
            $missing = [];

            foreach ($rowCounts as $entity => $expected) {
                if (($held[$entity] ?? 0) < $expected) {
                    $missing[] = ['entity' => $entity, 'expected' => $expected, 'received' => $held[$entity] ?? 0];
                }
            }

            $complete = ! $upload->isOpen() || ($missing === [] && $received >= $totalRows && $contiguous >= $highestSeq);
            $this->close($upload, $complete, $received, $contiguous, $missing, $snapshotSeq, $now);

            return [
                'uploadId' => $upload->id,
                'status' => $complete ? 'complete' : 'incomplete',
                'received' => $received,
                'resumeFromSeq' => $contiguous,
                'missing' => $complete ? [] : $missing,
                'pushResumesAfterSeq' => $snapshotSeq,
                'portalTimeUtc' => ApiDate::format($now),
                'messages' => [],
            ];
        });
    }

    /**
     * @param  list<array{entity: string, expected: int, received: int}>  $missing
     */
    private function close(CloudUpload $upload, bool $complete, int $received, int $contiguous, array $missing, int $snapshotSeq, CarbonImmutable $now): void
    {
        $wasOpen = $upload->isOpen();

        $upload->forceFill([
            'received_rows' => $received,
            'acknowledged_seq' => $contiguous,
            'complete_calls' => $upload->complete_calls + 1,
            'missing' => $complete ? [] : $missing,
            'status' => $complete ? CloudUploadStatus::Complete : CloudUploadStatus::Open,
            'completed_at' => $complete ? ($upload->completed_at ?? $now) : null,
        ])->save();

        if (! $complete || ! $wasOpen) {
            return;
        }

        // §17.8: the branch's delta push resumes after the snapshot's ChangeLog seq.
        DB::table('sync_branch_status')->where('branch_id', $upload->branch_id)
            ->where(fn ($q) => $q->whereNull('last_acknowledged_seq')->orWhere('last_acknowledged_seq', '<', $snapshotSeq))
            ->update(['last_acknowledged_seq' => $snapshotSeq, 'updated_at' => $now]);

        $this->audit->handle('cloud.migration_completed', $upload, ['status' => 'open'], [
            'status' => 'complete', 'received_rows' => $received, 'push_resumes_after_seq' => $snapshotSeq,
        ], ['source' => 'tillApi'], companyId: $upload->company_id);
    }
}
