<?php

namespace App\Http\Controllers\App;

use App\Domain\Sales\Actions\QueueSalesExport;
use App\Domain\Sales\Enums\ExportStatus;
use App\Domain\Sales\Models\SalesExport;
use App\Domain\Sales\Queries\SaleList;
use App\Domain\Sales\Queries\SaleReceipt;
use App\Domain\Sales\Queries\SaleSearch;
use App\Domain\Sales\Support\SalesCsv;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\Sale;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Sales\SalesFilterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sales and receipts of the tenant portal (module 4.6), read only: sales are the tills' (contract §16). List with
 * filters and keyset paging, the receipt page, and the CSV export of the filtered list (streamed up to
 * SalesExport::STREAM_ROWS sales, queued above). A one-shop user sees only their shop's sales.
 */
class SaleController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(SalesFilterRequest $request): Response
    {
        return Inertia::render('app/sales/index', SaleList::for($request, $request->filters(), $request->user()?->id));
    }

    public function show(string $sale): Response
    {
        $model = Sale::query()->findOrFail($sale);
        $restricted = $this->tenancy->restrictedBranchId();
        abort_if($restricted !== null && $model->branch_id !== $restricted, 404);

        return Inertia::render('app/sales/show', SaleReceipt::for($model));
    }

    public function export(SalesFilterRequest $request, QueueSalesExport $queue): StreamedResponse|RedirectResponse
    {
        $filters = $request->filters();
        $query = SaleSearch::query($filters);

        if (SaleSearch::countUpTo($query, SalesExport::STREAM_ROWS) > SalesExport::STREAM_ROWS) {
            $queue->handle($this->tenancy->require(), $request->user()?->id, $filters);

            return back()->with('success', 'That is a big export, so we are preparing it. It appears under Exports on this page when ready.');
        }

        $company = $this->tenancy->require();

        return response()->streamDownload(function () use ($company, $query) {
            $out = fopen('php://output', 'w');

            if ($out !== false) {
                $this->tenancy->runAs($company, fn () => SalesCsv::write($out, $query));
                fclose($out);
            }
        }, "sales-{$filters->from}-to-{$filters->to}.csv", ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function download(Request $request, string $export): StreamedResponse
    {
        $model = SalesExport::query()->where('user_id', $request->user()?->id)->findOrFail($export);
        abort_if($model->status !== ExportStatus::Ready || $model->path === null || $model->isExpired(), 404);
        abort_unless(Storage::disk(SalesExport::DISK)->exists($model->path), 404);

        return Storage::disk(SalesExport::DISK)->download($model->path, $model->fileName(), [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
