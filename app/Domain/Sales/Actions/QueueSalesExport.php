<?php

namespace App\Domain\Sales\Actions;

use App\Domain\Sales\Data\SaleFilters;
use App\Domain\Sales\Enums\ExportStatus;
use App\Domain\Sales\Jobs\BuildSalesExportJob;
use App\Domain\Sales\Models\SalesExport;
use App\Domain\Tenancy\Models\Company;

/**
 * Queue a sales CSV export too big to stream (module 4.6). The filters are stored as resolved — a one-shop user's
 * export is already pinned to their shop — and the job builds the file in the business's scope.
 */
class QueueSalesExport
{
    public function handle(Company $company, ?int $userId, SaleFilters $filters): SalesExport
    {
        $export = new SalesExport([
            'user_id' => $userId,
            'status' => ExportStatus::Queued,
            'filters' => $filters->toArray(),
        ]);
        $export->company_id = $company->id;
        $export->save();

        BuildSalesExportJob::dispatch($export->id, $company->id);

        return $export;
    }
}
