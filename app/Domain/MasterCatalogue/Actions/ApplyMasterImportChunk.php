<?php

namespace App\Domain\MasterCatalogue\Actions;

use App\Domain\Catalogue\Enums\ImportStatus;
use App\Domain\Catalogue\Import\CsvReader;
use App\Domain\MasterCatalogue\Enums\MasterSource;
use App\Domain\MasterCatalogue\Models\MasterCatalogueImport;
use App\Domain\MasterCatalogue\Models\MasterProduct;
use App\Domain\MasterCatalogue\Support\Gtin;
use App\Domain\MasterCatalogue\Support\MasterCsvColumns;
use App\Domain\Shared\Actions\RecordAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Applies one chunk of a master catalogue CSV from a byte offset (MasterImportJob). Rows are matched by barcode
 * (a barcode merged into another product updates the kept product); each row is saved through SaveMasterProduct on
 * its own, so one bad row never stops the rest. Idempotent: applying the same file twice changes nothing the second
 * time. Returns where the next chunk starts, or null when the file is done.
 */
final class ApplyMasterImportChunk
{
    public const CHUNK = 500;

    public function __construct(private readonly SaveMasterProduct $save, private readonly RecordAudit $audit) {}

    /**
     * @return array{offset: int, line: int}|null
     */
    public function handle(MasterCatalogueImport $import, int $offset = 0, int $line = 2): ?array
    {
        $reader = new CsvReader(Storage::disk(StartMasterImport::DISK)->path($import->path));
        $map = MasterCsvColumns::map($reader->headers());
        $rows = [];
        $next = null;

        foreach ($reader->rows($offset, $line) as $row) {
            if (count($rows) === self::CHUNK) {
                $next = ['offset' => $offset, 'line' => $row['line']];
                break;
            }

            $rows[$row['line']] = MasterCsvColumns::read($map, $row['cells']);
            $offset = $row['next'];
        }

        $known = $this->known($rows);
        $tally = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];
        $errors = $import->errors ?? [];

        foreach ($rows as $lineNo => $input) {
            try {
                $code = Gtin::normalise((string) ($input['barcode'] ?? ''));
                $product = $code === null ? null : ($known[$code] ?? null);

                if ($product !== null) {
                    unset($input['barcode']);
                }

                [$saved, $outcome] = DB::transaction(fn () => $this->save->handle($product, $input, MasterSource::Import, $import->source_ref, audit: false));
                $known[$saved->barcode] = $saved;
                $tally[$outcome]++;
            } catch (ValidationException $e) {
                $tally['failed']++;

                if (count($errors) < MasterCatalogueImport::MAX_ERRORS) {
                    $errors[] = ['row' => $lineNo, 'messages' => array_values(array_map('strval', array_merge([], ...array_values($e->errors()))))];
                }
            }
        }

        $import->forceFill([
            'status' => $next === null ? ImportStatus::Completed : ImportStatus::Running,
            'cursor' => $offset,
            'processed_rows' => $import->processed_rows + count($rows),
            'created_count' => $import->created_count + $tally['created'],
            'updated_count' => $import->updated_count + $tally['updated'],
            'unchanged_count' => $import->unchanged_count + $tally['unchanged'],
            'failed_count' => $import->failed_count + $tally['failed'],
            'errors' => $errors,
            'finished_at' => $next === null ? CarbonImmutable::now('UTC') : null,
        ])->save();

        if ($next === null) {
            $this->audit->handle('master_catalogue.import_completed', $import, null, [
                'created' => $import->created_count, 'updated' => $import->updated_count, 'unchanged' => $import->unchanged_count, 'failed' => $import->failed_count,
            ], ['file' => $import->file_name], $import->admin);
        }

        return $next;
    }

    /**
     * The chunk's barcodes already in the catalogue, merged rows resolved to the product they were merged into.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, MasterProduct>
     */
    private function known(array $rows): array
    {
        $codes = array_values(array_filter(array_map(fn (array $r) => Gtin::normalise((string) ($r['barcode'] ?? '')), $rows)));

        if ($codes === []) {
            return [];
        }

        $found = MasterProduct::query()->whereIn('barcode', $codes)->get();
        $targets = MasterProduct::query()->whereIn('id', $found->pluck('merged_into_id')->filter()->unique()->values())->get()->keyBy('id');
        $known = [];

        foreach ($found as $product) {
            $known[$product->barcode] = $product->merged_into_id === null ? $product : ($targets->get($product->merged_into_id) ?? $product);
        }

        return $known;
    }
}
