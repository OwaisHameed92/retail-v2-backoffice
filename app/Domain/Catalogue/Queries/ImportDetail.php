<?php

namespace App\Domain\Catalogue\Queries;

use App\Domain\Catalogue\Import\ImportColumns;
use App\Domain\Catalogue\Models\ProductImport;

/**
 * Props of the product import screens: the recent imports, and one import's mapping, preview, progress and result.
 */
final class ImportDetail
{
    /**
     * @return array<string, mixed>
     */
    public static function row(ProductImport $import): array
    {
        return [
            'id' => $import->id,
            'fileName' => $import->file_name,
            'status' => $import->status->value,
            'statusLabel' => $import->status->label(),
            'totalRows' => $import->total_rows,
            'validRows' => $import->valid_rows,
            'errorRows' => $import->error_rows,
            'processedRows' => $import->processed_rows,
            'created' => $import->created_count,
            'updated' => $import->updated_count,
            'unchanged' => $import->unchanged_count,
            'failed' => $import->failed_count,
            'by' => $import->user?->name,
            'createdAt' => $import->created_at?->toIso8601ZuluString(),
            'previewedAt' => $import->previewed_at?->toIso8601ZuluString(),
            'startedAt' => $import->started_at?->toIso8601ZuluString(),
            'finishedAt' => $import->finished_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recent(): array
    {
        return ProductImport::query()->with('user:id,name')->latest('created_at')->latest('id')->limit(10)->get()
            ->map(fn (ProductImport $i) => self::row($i))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function show(ProductImport $import): array
    {
        $import->loadMissing('user:id,name');

        return [
            'import' => [
                ...self::row($import),
                'headers' => $import->headers,
                'mapping' => (object) ($import->mapping ?? []),
                'preview' => $import->preview === null ? null : [
                    'new' => (int) ($import->preview['new'] ?? 0),
                    'update' => (int) ($import->preview['update'] ?? 0),
                    'sample' => $import->preview['sample'] ?? [],
                ],
                'errors' => $import->errors ?? [],
                'errorsTruncated' => count($import->errors ?? []) >= ProductImport::MAX_ERRORS,
            ],
            'fields' => ImportColumns::options(),
        ];
    }
}
