<?php

namespace App\Domain\Pharmacy\Actions;

use App\Domain\Pharmacy\Enums\DmdFetchOutcome;
use App\Domain\Pharmacy\Models\DmdFetchRun;
use App\Domain\Pharmacy\Models\DmdRelease;
use App\Domain\Pharmacy\Support\DmdReleaseStore;
use App\Domain\Pharmacy\Support\DmdSwitch;
use App\Domain\Pharmacy\Support\TrudClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The weekly dm+d fetch (W4, PORTAL-CHANGES-pharmacy.md item 12 "Publishing a new week"), run by `pharmacy:dmd-fetch`
 * (Monday, retried Tuesday for a bank-holiday week). Only with the switch on and a TRUD key set; one run at a time.
 *
 * 1. TRUD item 24's latest release (our key). Already held in full → unchanged (idempotent).
 * 2. Its zip to a local temp file; size and SHA-256 must equal TRUD's `archiveFileSizeBytes` / `archiveFileSha256`,
 *    else it is thrown away and nothing changes.
 * 3. DmdReleaseStore::publish(): stored unchanged, then the manifest row in one write; then the old weeks pruned.
 *
 * Every run is recorded in `dmd_fetch_runs`; a failure is logged as an error (the alert) with a redacted message.
 * The key is never logged.
 */
class FetchDmdRelease
{
    public function __construct(private readonly TrudClient $trud) {}

    public function handle(): DmdFetchOutcome
    {
        if (! DmdSwitch::enabled() || ! DmdSwitch::hasTrudKey()) {
            return DmdFetchOutcome::Skipped;
        }

        $lock = Cache::lock('pharmacy:dmd-fetch', 3600);

        if (! $lock->get()) {
            return DmdFetchOutcome::Skipped;
        }

        try {
            return $this->run();
        } finally {
            $lock->release();
        }
    }

    private function run(): DmdFetchOutcome
    {
        $run = DmdFetchRun::query()->create(['outcome' => DmdFetchOutcome::Failed, 'started_at' => CarbonImmutable::now()]);
        $temp = null;
        $releaseId = null;

        try {
            $release = $this->trud->latestRelease();
            $releaseId = $release->releaseId();
            $held = DmdRelease::query()->where('release_id', $releaseId)->first();

            if ($held !== null && DmdReleaseStore::holds($held)) {
                DmdReleaseStore::prune();

                return $this->finish($run, DmdFetchOutcome::Unchanged, $releaseId);
            }

            $temp = (string) tempnam(sys_get_temp_dir(), 'dmd-');
            $this->trud->download($release, $temp);
            clearstatcache(true, $temp);
            $size = (int) filesize($temp);
            $sha = (string) hash_file('sha256', $temp);

            if (! hash_equals($release->sha256, $sha)) {
                throw new RuntimeException("{$release->archiveFileName} does not match TRUD's archiveFileSha256; thrown away.");
            }

            if ($release->sizeBytes !== null && $release->sizeBytes !== $size) {
                throw new RuntimeException("{$release->archiveFileName} is {$size} bytes, TRUD lists {$release->sizeBytes}; thrown away.");
            }

            DmdReleaseStore::publish($release, $temp, $size, $sha, CarbonImmutable::now());
            $removed = DmdReleaseStore::prune();
            Log::info('dm+d release published', ['releaseId' => $releaseId, 'sizeBytes' => $size, 'removed' => $removed]);

            return $this->finish($run, DmdFetchOutcome::Published, $releaseId);
        } catch (Throwable $e) {
            $message = DmdSwitch::redact($e->getMessage());
            Log::error('dm+d fetch failed', ['error' => $message, 'releaseId' => $releaseId]);

            return $this->finish($run, DmdFetchOutcome::Failed, $releaseId, $message);
        } finally {
            if ($temp !== null && is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    private function finish(DmdFetchRun $run, DmdFetchOutcome $outcome, ?string $releaseId, ?string $error = null): DmdFetchOutcome
    {
        $run->update(['outcome' => $outcome, 'release_id' => $releaseId, 'error' => $error, 'finished_at' => CarbonImmutable::now()]);

        return $outcome;
    }
}
