<?php

namespace App\Domain\Catalogue\Actions;

use App\Domain\Catalogue\Enums\ImportStatus;
use App\Domain\Catalogue\Jobs\ApplyProductImportJob;
use App\Domain\Catalogue\Models\ProductImport;
use App\Domain\Shared\Actions\RecordAudit;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Step 3 of a product CSV import: queues the apply (ApplyProductImportJob, one chunk of rows per job). Only a
 * previewed import with rows to apply, and one import per business at a time (two imports of the same file would
 * race for the same new barcodes).
 */
final class QueueProductImport
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(ProductImport $import): ProductImport
    {
        if ($import->status !== ImportStatus::Uploaded || $import->previewed_at === null) {
            throw ValidationException::withMessages(['import' => 'Check the preview before importing.']);
        }

        if ($import->valid_rows === 0) {
            throw ValidationException::withMessages(['import' => 'No row can be imported. Fix the file and upload it again.']);
        }

        if (ProductImport::query()->whereKeyNot($import->id)->whereIn('status', [ImportStatus::Queued, ImportStatus::Running])->exists()) {
            throw ValidationException::withMessages(['import' => 'Another import is still running. Wait for it to finish.']);
        }

        $import->forceFill([
            'status' => ImportStatus::Queued, 'cursor' => 0, 'processed_rows' => 0, 'created_count' => 0, 'updated_count' => 0,
            'unchanged_count' => 0, 'failed_count' => 0, 'errors' => [], 'started_at' => CarbonImmutable::now('UTC'), 'finished_at' => null,
        ])->save();

        $this->audit->handle('product_import.queued', $import, null, null, ['file' => $import->file_name, 'rows' => $import->valid_rows]);

        ApplyProductImportJob::dispatch($import->id, $import->company_id);

        return $import;
    }
}
