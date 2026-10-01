<?php

namespace App\Domain\MasterCatalogue\Jobs;

use App\Domain\Catalogue\Enums\ImportStatus;
use App\Domain\MasterCatalogue\Actions\ApplyMasterImportChunk;
use App\Domain\MasterCatalogue\Models\MasterCatalogueImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Applies one chunk of a master catalogue CSV (ApplyMasterImportChunk), then queues the next chunk, so progress shows
 * on /admin/catalogue/imports and a huge supplier file never holds a worker for long. A retried chunk is safe: rows
 * are matched by barcode and unchanged rows write nothing.
 */
class MasterImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public readonly string $importId, public readonly int $offset = 0, public readonly int $line = 2) {}

    public function handle(ApplyMasterImportChunk $apply): void
    {
        $import = MasterCatalogueImport::query()->find($this->importId);

        if ($import === null || ! $import->status->isApplying()) {
            return;
        }

        $next = $apply->handle($import, $this->offset, $this->line);

        if ($next !== null) {
            self::dispatch($this->importId, $next['offset'], $next['line']);
        }
    }

    public function failed(Throwable $e): void
    {
        MasterCatalogueImport::query()->whereKey($this->importId)
            ->update(['status' => ImportStatus::Failed->value, 'finished_at' => now('UTC')->format('Y-m-d H:i:s')]);

        report($e);
    }
}
