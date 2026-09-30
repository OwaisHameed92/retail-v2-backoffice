<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Data\SaleFilters;
use App\Domain\Sales\Enums\ExportStatus;
use App\Domain\Sales\Models\SalesExport;
use App\Domain\Sales\Queries\SaleSearch;
use App\Domain\Sales\Support\SalesCsv;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Build a queued sales export's CSV into the private disk (module 4.6). Runs inside the export's business scope
 * (BuildSalesExportJob). A rerun rewrites the same file.
 */
class BuildSalesExport
{
    public function handle(SalesExport $export): SalesExport
    {
        $export->update(['status' => ExportStatus::Running]);

        $path = "sales-exports/{$export->company_id}/{$export->id}.csv";
        $disk = Storage::disk(SalesExport::DISK);
        $disk->makeDirectory(dirname($path));
        $handle = fopen($disk->path($path), 'w');

        if ($handle === false) {
            throw new RuntimeException('Could not open the export file.');
        }

        try {
            $rows = SalesCsv::write($handle, SaleSearch::query(SaleFilters::fromArray($export->filters)));
        } finally {
            fclose($handle);
        }

        $export->update([
            'status' => ExportStatus::Ready,
            'path' => $path,
            'row_count' => $rows,
            'finished_at' => now('UTC'),
        ]);

        return $export;
    }
}
