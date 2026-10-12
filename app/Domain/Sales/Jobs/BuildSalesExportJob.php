<?php

namespace App\Domain\Sales\Jobs;

use App\Domain\Sales\Actions\BuildSalesExport;
use App\Domain\Sales\Enums\ExportStatus;
use App\Domain\Sales\Models\SalesExport;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Builds one queued sales CSV export as its business (module 4.6). The longest job: its timeout stays below the
 * queue's retry_after (1900 s, config/queue.php) and the worker's --timeout. A second copy of it (a redelivery)
 * does nothing while the first runs: BuildSalesExport claims the export atomically.
 */
class BuildSalesExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const TIMEOUT = 1800;

    public int $tries = 2;

    public int $timeout = self::TIMEOUT;

    public function __construct(public readonly string $exportId, public readonly string $companyId) {}

    public function handle(CurrentCompany $tenancy, BuildSalesExport $build): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company === null) {
            return;
        }

        $tenancy->runAs($company, function () use ($build) {
            $export = SalesExport::query()->find($this->exportId);

            if ($export !== null && $export->status->isPending()) {
                $build->handle($export);
            }
        });
    }

    public function failed(Throwable $e): void
    {
        $company = Company::query()->find($this->companyId);

        if ($company !== null) {
            app(CurrentCompany::class)->runAs($company, fn () => SalesExport::query()->whereKey($this->exportId)
                ->update(['status' => ExportStatus::Failed->value, 'finished_at' => now('UTC')->format('Y-m-d H:i:s')]));
        }

        report($e);
    }
}
