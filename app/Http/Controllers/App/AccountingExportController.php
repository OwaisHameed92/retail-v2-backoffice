<?php

namespace App\Http\Controllers\App;

use App\Domain\Accounts\Export\ExportJournals;
use App\Domain\Accounts\Export\ExportPages;
use App\Domain\Accounts\Export\SaveExportMappings;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Accounts\AccountingExportRequest;
use App\Http\Requests\App\Accounts\ExportMappingRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Accounting exports (gap #8, `accounts.export`: owner and accountant): the period's summary journals for Xero,
 * QuickBooks Online, Sage 50 or Sage Accounting, previewed then downloaded as CSV, and the account / VAT code mapping
 * per package. Built from the tills' journals (module 5.5); a one-shop user exports only their shop.
 */
class AccountingExportController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(AccountingExportRequest $request): Response
    {
        $filters = $request->filters();
        $shops = Branch::query()->when($filters->shopLocked, fn ($q) => $q->whereKey($filters->shop))->orderBy('name')->get(['id', 'name']);

        return Inertia::render('app/accounts/export', [
            ...ExportPages::preview($filters, $request->target(), $request->grouping(), (string) $this->tenancy->require()->name),
            'filters' => $filters->toArray(),
            'options' => ['shops' => $shops->map(fn (Branch $b) => ['value' => (string) $b->id, 'label' => (string) $b->name])->values()->all()],
        ]);
    }

    public function download(AccountingExportRequest $request, ExportJournals $export): HttpResponse
    {
        $result = $export->handle($this->tenancy->require(), $request->filters(), $request->target(), $request->grouping());

        return response($result['csv'], 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$result['filename'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function mappings(AccountingExportRequest $request): Response
    {
        return Inertia::render('app/accounts/export-mappings', [
            ...ExportPages::mappings($request->target()),
            'filters' => $request->filters()->toArray(),
            'canEdit' => $this->tenancy->restrictedBranchId() === null,
        ]);
    }

    public function updateMappings(ExportMappingRequest $request, SaveExportMappings $save): RedirectResponse
    {
        $changed = $save->handle($this->tenancy->require(), $request->target(), $request->accounts(), $request->vat());

        return back()->with('success', $changed === 0 ? 'Nothing to save: no changes.' : "Mapping saved for {$request->target()->label()}.");
    }
}
