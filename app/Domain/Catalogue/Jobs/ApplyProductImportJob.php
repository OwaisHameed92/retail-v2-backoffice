<?php

namespace App\Domain\Catalogue\Jobs;

use App\Domain\Catalogue\Actions\ApplyProductImportChunk;
use App\Domain\Catalogue\Enums\ImportStatus;
use App\Domain\Catalogue\Models\ProductImport;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Applies one chunk of a product CSV import as its business (ApplyProductImportChunk), then queues the next chunk, so
 * a 100k-row file never holds a worker for long and progress shows on the import screen. A retried chunk is safe:
 * rows are found by barcode or code and unchanged rows write nothing.
 */
class ApplyProductImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public readonly string $importId,
        public readonly string $companyId,
        public readonly int $offset = 0,
        public readonly int $line = 2,
    ) {}

    public function handle(CurrentCompany $tenancy, ApplyProductImportChunk $apply): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company === null) {
            return;
        }

        $next = $tenancy->runAs($company, function () use ($apply) {
            $import = ProductImport::query()->find($this->importId);

            if ($import === null || ! $import->status->isApplying()) {
                return null;
            }

            return $apply->handle($import, $this->offset, $this->line);
        });

        if ($next !== null) {
            self::dispatch($this->importId, $this->companyId, $next['offset'], $next['line']);
        }
    }

    public function failed(Throwable $e): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company !== null) {
            app(CurrentCompany::class)->runAs($company, fn () => ProductImport::query()->whereKey($this->importId)
                ->update(['status' => ImportStatus::Failed->value, 'finished_at' => now('UTC')->format('Y-m-d H:i:s')]));
        }

        report($e);
    }
}
