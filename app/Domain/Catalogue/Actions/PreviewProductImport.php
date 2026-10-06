<?php

namespace App\Domain\Catalogue\Actions;

use App\Domain\Catalogue\Enums\ImportStatus;
use App\Domain\Catalogue\Import\CsvReader;
use App\Domain\Catalogue\Import\ImportColumns;
use App\Domain\Catalogue\Import\ImportLookups;
use App\Domain\Catalogue\Import\ImportPlanner;
use App\Domain\Catalogue\Import\ImportRow;
use App\Domain\Catalogue\Import\RowInterpreter;
use App\Domain\Catalogue\Models\ProductImport;
use App\Domain\Shared\Country\MoneyFormat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Step 2 of a product CSV import: saves the column mapping and checks every row without writing anything: format,
 * how the product is found (barcode, then code), what a new product needs, and repeats in the file (the first row
 * wins; later ones are skipped). Stores the counts, the row errors (first 500) and the first rows as they will be read.
 */
final class PreviewProductImport
{
    public const MAX_ROWS = 200000;

    private const BATCH = 500;

    private const SAMPLE = 20;

    /**
     * @param  array<mixed>  $mapping  field → column index
     */
    public function handle(ProductImport $import, array $mapping): ProductImport
    {
        if ($import->status !== ImportStatus::Uploaded) {
            throw ValidationException::withMessages(['mapping' => 'This import has already been applied. Upload the file again to start a new one.']);
        }

        $mapping = ImportColumns::clean($mapping, $import->headers);

        if (! isset($mapping['barcode']) && ! isset($mapping['sku'])) {
            throw ValidationException::withMessages(['mapping' => 'Choose the column with the barcode or the product code: it is how products are found.']);
        }

        $interpreter = new RowInterpreter($mapping);
        $planner = new ImportPlanner(ImportLookups::load());
        $state = ['total' => 0, 'errorRows' => 0, 'new' => 0, 'update' => 0, 'errors' => [], 'sample' => [], 'skip' => [], 'seen' => []];
        $batch = [];

        foreach ((new CsvReader(Storage::disk(StartProductImport::DISK)->path($import->path)))->rows() as $row) {
            if (++$state['total'] > self::MAX_ROWS) {
                throw ValidationException::withMessages(['mapping' => 'The file has more than '.MoneyFormat::number(self::MAX_ROWS).' rows. Split it into smaller files.']);
            }

            $batch[] = $interpreter->read($row['line'], $row['cells']);

            if (count($batch) === self::BATCH) {
                $this->check($planner, $batch, $state);
                $batch = [];
            }
        }

        $this->check($planner, $batch, $state);

        $import->forceFill([
            'mapping' => $mapping,
            'total_rows' => $state['total'],
            'valid_rows' => $state['total'] - $state['errorRows'],
            'error_rows' => $state['errorRows'],
            'errors' => $state['errors'],
            'preview' => ['new' => $state['new'], 'update' => $state['update'], 'sample' => $state['sample'], 'skipLines' => $state['skip']],
            'previewed_at' => CarbonImmutable::now('UTC'),
        ])->save();

        return $import;
    }

    /**
     * @param  list<ImportRow>  $batch
     * @param  array{total: int, errorRows: int, new: int, update: int, errors: list<array{row: int, messages: list<string>}>, sample: list<array<string, mixed>>, skip: list<int>, seen: array<string, int>}  $state
     */
    private function check(ImportPlanner $planner, array $batch, array &$state): void
    {
        if ($batch === []) {
            return;
        }

        $plan = $planner->plan($batch);

        foreach ($batch as $row) {
            $keys = array_filter(['barcode' => $row->barcode, 'code' => $row->sku]);

            foreach ($row->errors === [] ? $keys : [] as $what => $value) {
                $first = $state['seen'][$what.':'.mb_strtolower($value)] ?? null;

                if ($first !== null && $row->errors === []) {
                    $row->errors[] = "Same {$what} as line {$first}: only the first is imported.";
                    $state['skip'][] = $row->line;
                }
            }

            foreach ($row->errors === [] ? $keys : [] as $what => $value) {
                $state['seen'][$what.':'.mb_strtolower($value)] = $row->line;
            }

            $product = $plan[$row->line] ?? null;

            if ($row->errors !== []) {
                $state['errorRows']++;

                if (count($state['errors']) < ProductImport::MAX_ERRORS) {
                    $state['errors'][] = ['row' => $row->line, 'messages' => $row->errors];
                }
            } else {
                $product === null ? $state['new']++ : $state['update']++;
            }

            if (count($state['sample']) < self::SAMPLE) {
                $state['sample'][] = [
                    'line' => $row->line, 'barcode' => $row->barcode, 'sku' => $row->sku,
                    'name' => $row->attributes['name'] ?? $product?->name, 'sellPrice' => $row->attributes['sell_price'] ?? null,
                    'department' => $row->department, 'category' => $row->category,
                    'action' => $row->errors !== [] ? 'skip' : ($product === null ? 'create' : 'update'), 'errors' => $row->errors,
                ];
            }
        }
    }
}
