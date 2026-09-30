<?php

namespace App\Http\Controllers\App;

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Transfers\Data\TransferFilters;
use App\Domain\Transfers\Queries\DiscrepancyReport;
use App\Domain\Transfers\Queries\TransferDetail;
use App\Domain\Transfers\Queries\TransferList;
use App\Domain\Transfers\Support\TransfersCsv;
use App\Domain\Transfers\Support\TransferState;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stock transfers between shops on the tenant portal (module 5.3, `company.can:transfers.view`), read only: the shops
 * own them (contract ownership.json: `branch`, relayed §10.2, not `hubDrafted`), so the portal cannot raise one. List,
 * one transfer, the discrepancy report and CSVs. A one-shop user sees transfers from or to their shop only (another
 * transfer is "not found").
 */
class TransferController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(Request $request): Response
    {
        return Inertia::render('app/transfers/index', TransferList::for($request));
    }

    public function show(string $transfer): Response
    {
        $model = TransferState::query()->findOrFail($transfer);
        $restricted = $this->tenancy->restrictedBranchId();
        abort_if($restricted !== null && ! in_array($restricted, [$model->from_branch_id, $model->to_branch_id], true), 404);

        return Inertia::render('app/transfers/show', TransferDetail::for($model));
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = TransferFilters::from($request);
        $query = TransferList::filtered($filters);

        return $this->csv(fn ($out) => TransfersCsv::list($out, $query), TransfersCsv::filename('stock-transfers', $filters));
    }

    public function discrepancies(Request $request): Response
    {
        return Inertia::render('app/transfers/discrepancies', DiscrepancyReport::for(DiscrepancyReport::filters($request)));
    }

    public function discrepanciesExport(Request $request): StreamedResponse
    {
        $filters = DiscrepancyReport::filters($request);

        return $this->csv(fn ($out) => TransfersCsv::discrepancies($out, $filters), TransfersCsv::filename('transfer-discrepancies', $filters));
    }

    /** @param callable(resource): void $write */
    private function csv(callable $write, string $filename): StreamedResponse
    {
        $company = $this->tenancy->require();
        $role = $this->tenancy->role();
        $restricted = $this->tenancy->restrictedBranchId();

        return response()->streamDownload(function () use ($write, $company, $role, $restricted) {
            $out = fopen('php://output', 'w');

            if ($out !== false) {
                // The stream may run after the request's tenancy is cleared: run it as the same company, role and shop.
                $this->tenancy->runAs($company, function () use ($write, $out, $company, $role, $restricted) {
                    $this->tenancy->set($company, $role, $restricted);
                    $write($out);
                }, $role);
                fclose($out);
            }
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
