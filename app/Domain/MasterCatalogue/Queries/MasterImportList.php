<?php

namespace App\Domain\MasterCatalogue\Queries;

use App\Domain\MasterCatalogue\Models\MasterCatalogueImport;
use App\Domain\MasterCatalogue\Support\MasterCsvColumns;

/** The admin catalogue loads screen: the latest CSV loads with progress, results and row errors; the columns read. */
final class MasterImportList
{
    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return [
            'imports' => MasterCatalogueImport::query()->with('admin:id,name')->latest('created_at')->limit(20)->get()
                ->map(fn (MasterCatalogueImport $i) => [
                    'id' => $i->id,
                    'fileName' => $i->file_name,
                    'sourceRef' => $i->source_ref,
                    'status' => $i->status->value,
                    'statusLabel' => $i->status->label(),
                    'processed' => $i->processed_rows,
                    'created' => $i->created_count,
                    'updated' => $i->updated_count,
                    'unchanged' => $i->unchanged_count,
                    'failed' => $i->failed_count,
                    'errors' => array_slice($i->errors ?? [], 0, 50),
                    'by' => $i->admin?->name,
                    'createdAt' => $i->created_at?->toIso8601ZuluString(),
                    'finishedAt' => $i->finished_at?->toIso8601ZuluString(),
                ])->all(),
            'columns' => array_map(fn (string $field, array $names) => ['field' => $field, 'names' => $names], array_keys(MasterCsvColumns::FIELDS), MasterCsvColumns::FIELDS),
        ];
    }
}
