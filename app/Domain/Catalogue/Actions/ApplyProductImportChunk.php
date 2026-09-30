<?php

namespace App\Domain\Catalogue\Actions;

use App\Domain\Catalogue\Enums\ImportStatus;
use App\Domain\Catalogue\Import\CsvReader;
use App\Domain\Catalogue\Import\ImportLookups;
use App\Domain\Catalogue\Import\ImportPlanner;
use App\Domain\Catalogue\Import\ImportRow;
use App\Domain\Catalogue\Import\RowInterpreter;
use App\Domain\Catalogue\Models\ProductImport;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductBarcode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Applies one chunk of a product CSV import from a byte offset (ApplyProductImportJob). Every row is checked again
 * against the business as it is now, then saved through SaveProduct (hub-owned rows: tills receive them in their next
 * pull), each row on its own, so one bad row never undoes the others. Idempotent: a product is found by barcode, then
 * by code, and a row that changes nothing writes nothing, so applying the same file twice creates nothing twice.
 *
 * Returns where the next chunk starts, or null when the file is done (the import is then completed and audited).
 */
final class ApplyProductImportChunk
{
    public const CHUNK = 250;

    public function __construct(
        private readonly SaveProduct $save,
        private readonly SaveDepartment $saveDepartment,
        private readonly SaveCategory $saveCategory,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @return array{offset: int, line: int}|null
     */
    public function handle(ProductImport $import, int $offset = 0, int $line = 2): ?array
    {
        $interpreter = new RowInterpreter($import->mapping ?? []);
        $lookups = ImportLookups::load();
        $skip = array_flip($import->preview['skipLines'] ?? []);
        $rows = [];
        $next = null;

        foreach ((new CsvReader(Storage::disk(StartProductImport::DISK)->path($import->path)))->rows($offset, $line) as $row) {
            if (count($rows) === self::CHUNK) {
                $next = ['offset' => $offset, 'line' => $row['line']];
                break;
            }

            $read = $interpreter->read($row['line'], $row['cells']);

            if (isset($skip[$row['line']])) {
                $read->errors[] = 'Same barcode or code as an earlier line: only the first is imported.';
            }

            $rows[] = $read;
            $offset = $row['next'];
        }

        $plan = (new ImportPlanner($lookups))->plan($rows);
        $tally = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];
        $errors = $import->errors ?? [];

        foreach ($rows as $row) {
            $outcome = $row->errors === [] ? $this->apply($row, $plan[$row->line] ?? null, $lookups) : $row->errors;

            if (is_array($outcome)) {
                $tally['failed']++;

                if (count($errors) < ProductImport::MAX_ERRORS) {
                    $errors[] = ['row' => $row->line, 'messages' => $outcome];
                }
            } else {
                $tally[$outcome]++;
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
            $this->audit->handle('product_import.completed', $import, null, [
                'created' => $import->created_count, 'updated' => $import->updated_count, 'unchanged' => $import->unchanged_count, 'failed' => $import->failed_count,
            ], ['file' => $import->file_name], $import->user);
        }

        return $next;
    }

    /**
     * @return 'created'|'updated'|'unchanged'|list<string>
     */
    private function apply(ImportRow $row, ?Product $product, ImportLookups $lookups): string|array
    {
        try {
            $attributes = $row->attributes;
            $departmentId = $row->department !== null ? $lookups->ensureDepartment($row->department, $this->saveDepartment) : $product?->department_id;

            if ($row->department !== null) {
                $attributes['department_id'] = $departmentId;
            }

            if ($row->category !== null && $departmentId !== null) {
                $attributes['category_id'] = $lookups->ensureCategory($departmentId, $row->category, $this->saveCategory);
            }

            if ($row->vat !== null) {
                $attributes['vat_rate_id'] = $lookups->vatId($row->vat);
            } elseif ($product === null) {
                $attributes['vat_rate_id'] = $lookups->defaultVat($departmentId, $attributes['category_id'] ?? null);
            }

            $saved = $this->save->handle($product, $attributes, $this->barcodes($row, $product), null, audit: false);

            return $saved->created ? 'created' : ($saved->changed === [] ? 'unchanged' : 'updated');
        } catch (ValidationException $e) {
            return array_values(array_map('strval', array_merge([], ...array_values($e->errors()))));
        }
    }

    /**
     * The product's barcodes with the row's added, or null to leave them (no barcode, or the product has it already).
     *
     * @return list<array{id?: string|null, barcode: string, pack_qty?: int|string|null, is_primary?: bool|null}>|null
     */
    private function barcodes(ImportRow $row, ?Product $product): ?array
    {
        if ($row->barcode === null) {
            return null;
        }

        if ($product === null) {
            return [['barcode' => $row->barcode, 'pack_qty' => 1, 'is_primary' => true]];
        }

        $existing = ProductBarcode::query()->where('product_id', $product->id)->orderByDesc('is_primary')->orderBy('created_at')->get();

        if ($existing->contains('barcode', $row->barcode)) {
            return null;
        }

        return [
            ...$existing->map(fn (ProductBarcode $b) => ['id' => $b->id, 'barcode' => $b->barcode, 'pack_qty' => $b->pack_qty, 'is_primary' => $b->is_primary])->all(),
            ['barcode' => $row->barcode, 'pack_qty' => 1, 'is_primary' => $existing->isEmpty()],
        ];
    }
}
