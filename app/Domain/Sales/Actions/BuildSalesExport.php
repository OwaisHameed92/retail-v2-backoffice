<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Data\SaleFilters;
use App\Domain\Sales\Enums\ExportStatus;
use App\Domain\Sales\Jobs\BuildSalesExportJob;
use App\Domain\Sales\Models\SalesExport;
use App\Domain\Sales\Queries\SaleSearch;
use App\Domain\Sales\Support\SalesCsv;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Build a queued sales export's CSV into the private disk (module 4.6). Runs inside the export's business scope
 * (BuildSalesExportJob). A rerun rewrites the same file.
 *
 * Safe against a second copy of the job (audit DATA-1): the export is claimed atomically (queued → running; only one
 * copy wins, the other returns null and does nothing), and the CSV is written to a partial file of its own and
 * renamed into place when complete, so no copy ever truncates a file another one is writing. A copy that died (killed
 * at the job's timeout) leaves the export running; once that is older than the timeout a retry may claim it again.
 * An error puts the export back to queued for the job's retry (`failed()` marks it failed after the last try).
 */
class BuildSalesExport
{
    /** A claim older than this belongs to a copy the job's timeout has killed. */
    public const STALE_AFTER_SECONDS = BuildSalesExportJob::TIMEOUT;

    /** The export, ready; null when another copy holds it (or it is not waiting to be built). */
    public function handle(SalesExport $export): ?SalesExport
    {
        $claimedAt = now('UTC')->format('Y-m-d H:i:s');

        if (! $this->claim($export, $claimedAt)) {
            return null;
        }

        try {
            [$path, $rows] = $this->write($export);
        } catch (Throwable $e) {
            // Back to queued for the job's retry, unless another copy has taken it over meanwhile.
            SalesExport::query()->whereKey($export->id)->where('status', ExportStatus::Running->value)->where('updated_at', $claimedAt)
                ->update(['status' => ExportStatus::Queued->value]);

            throw $e;
        }

        $export->refresh()->update([
            'status' => ExportStatus::Ready,
            'path' => $path,
            'row_count' => $rows,
            'finished_at' => now('UTC'),
        ]);

        return $export;
    }

    /** Queued (or running and stale) → running, in one UPDATE: true for the one copy that wins. */
    private function claim(SalesExport $export, string $claimedAt): bool
    {
        $stale = now('UTC')->subSeconds(self::STALE_AFTER_SECONDS)->format('Y-m-d H:i:s');

        return SalesExport::query()->whereKey($export->id)
            ->where(fn ($q) => $q->where('status', ExportStatus::Queued->value)
                ->orWhere(fn ($q) => $q->where('status', ExportStatus::Running->value)->where('updated_at', '<', $stale)))
            ->update(['status' => ExportStatus::Running->value, 'updated_at' => $claimedAt]) === 1;
    }

    /**
     * The CSV into `{id}.{random}.csv.partial`, then renamed to `{id}.csv`.
     *
     * @return array{0: string, 1: int} the file's path on the disk and the number of sales
     */
    private function write(SalesExport $export): array
    {
        $path = "sales-exports/{$export->company_id}/{$export->id}.csv";
        $disk = Storage::disk(SalesExport::DISK);
        $disk->makeDirectory(dirname($path));
        $partial = $disk->path("sales-exports/{$export->company_id}/{$export->id}.".bin2hex(random_bytes(6)).'.csv.partial');
        $handle = fopen($partial, 'w');

        if ($handle === false) {
            throw new RuntimeException('Could not open the export file.');
        }

        try {
            try {
                $rows = SalesCsv::write($handle, SaleSearch::query(SaleFilters::fromArray($export->filters)));
            } finally {
                fclose($handle);
            }

            if (! rename($partial, $disk->path($path))) {
                throw new RuntimeException('Could not move the export file into place.');
            }
        } finally {
            if (is_file($partial)) {
                @unlink($partial);
            }
        }

        return [$path, $rows];
    }
}
