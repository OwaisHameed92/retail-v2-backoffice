<?php

namespace App\Domain\MasterCatalogue\Actions;

use App\Domain\Admin\Models\Admin;
use App\Domain\Catalogue\Enums\ImportStatus;
use App\Domain\Catalogue\Import\CsvReader;
use App\Domain\MasterCatalogue\Jobs\MasterImportJob;
use App\Domain\MasterCatalogue\Models\MasterCatalogueImport;
use App\Domain\MasterCatalogue\Support\MasterCsvColumns;
use App\Domain\Shared\Actions\RecordAudit;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * An admin CSV load into the master catalogue: keeps the file (private `local` disk), checks the header has a
 * barcode and a name column, and queues MasterImportJob, which applies it in chunks (a 500k-row supplier file never
 * holds a worker for long). Rows are matched by barcode: new barcodes are added, known ones updated from the
 * non-empty cells. One load at a time.
 */
final class StartMasterImport
{
    public const DISK = 'local';

    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(UploadedFile $file, ?string $sourceRef, ?Admin $admin): MasterCatalogueImport
    {
        if (MasterCatalogueImport::query()->whereIn('status', [ImportStatus::Queued, ImportStatus::Running])->exists()) {
            throw ValidationException::withMessages(['file' => 'Another catalogue load is still running. Wait for it to finish.']);
        }

        $path = $file->storeAs('master-catalogue-imports', Str::lower((string) Str::ulid()).'.csv', self::DISK);

        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be saved. Please try again.']);
        }

        $map = MasterCsvColumns::map((new CsvReader(Storage::disk(self::DISK)->path($path)))->headers());

        if (! isset($map['barcode'], $map['name'])) {
            Storage::disk(self::DISK)->delete($path);

            throw ValidationException::withMessages(['file' => 'The first line must name the columns, with at least a barcode and a name column.']);
        }

        $fileName = mb_substr($file->getClientOriginalName(), 0, 255);
        $import = MasterCatalogueImport::query()->create([
            'admin_id' => $admin?->id,
            'file_name' => $fileName,
            'path' => $path,
            'source_ref' => trim((string) $sourceRef) === '' ? $fileName : mb_substr(trim((string) $sourceRef), 0, 255),
            'status' => ImportStatus::Queued,
            'errors' => [],
            'started_at' => CarbonImmutable::now('UTC'),
        ]);

        $this->audit->handle('master_catalogue.import_queued', $import, null, null, ['file' => $fileName, 'source' => $import->source_ref]);

        MasterImportJob::dispatch($import->id);

        return $import;
    }
}
