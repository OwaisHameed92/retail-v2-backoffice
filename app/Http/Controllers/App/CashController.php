<?php

namespace App\Http\Controllers\App;

use App\Domain\Cash\Data\CashFilters;
use App\Domain\Cash\Queries\CardReconciliation;
use App\Domain\Cash\Queries\CashOffice;
use App\Domain\Cash\Queries\DayLockBoard;
use App\Domain\Cash\Queries\ShiftDetail;
use App\Domain\Cash\Queries\ShiftList;
use App\Domain\Cash\Queries\VarianceAlerts;
use App\Domain\Cash\Queries\ZReportList;
use App\Domain\Cash\Support\CashLookup;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\Shift;
use App\Domain\TillData\Models\ZReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Cash\CashFilterRequest;
use Illuminate\Database\Eloquent\Model;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cash and Z of the tenant portal (module 5.4), read only: every row here is the tills' (branch-owned,
 * ownership.json), including day locks. Shifts, Z reports, banking, safe counts, card settlement reconciliation,
 * day locks and variance alerts. A one-shop user sees only their shop.
 */
class CashController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(CashFilterRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/cash/shifts', $filters, ShiftList::for($request, $filters));
    }

    public function shift(string $shift): Response
    {
        return Inertia::render('app/cash/shift', ShiftDetail::for($this->mine(Shift::query()->findOrFail($shift))));
    }

    public function zReports(CashFilterRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/cash/z-reports', $filters, ZReportList::for($request, $filters));
    }

    public function zReport(string $zReport): Response
    {
        return Inertia::render('app/cash/z-report', ZReportList::show($this->mine(ZReport::query()->findOrFail($zReport))));
    }

    public function banking(CashFilterRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/cash/banking', $filters, CashOffice::banking($request, $filters));
    }

    public function counts(CashFilterRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/cash/counts', $filters, CashOffice::counts($request, $filters));
    }

    public function cards(CashFilterRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/cash/cards', $filters, CardReconciliation::for($filters, $request->page(), $request->perPage()));
    }

    public function days(CashFilterRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/cash/days', $filters, DayLockBoard::for($filters, $request->page(), $request->perPage()));
    }

    public function alerts(CashFilterRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/cash/alerts', $filters, VarianceAlerts::for($filters, $request->page(), $request->perPage()));
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function page(string $component, CashFilters $filters, array $props): Response
    {
        return Inertia::render($component, [...$props, 'filters' => $filters->toArray(), 'options' => CashLookup::options($filters)]);
    }

    /**
     * A row of another shop is not found for a one-shop user.
     *
     * @template TModel of Model
     *
     * @param  TModel  $row
     * @return TModel
     */
    private function mine(Model $row): Model
    {
        $restricted = $this->tenancy->restrictedBranchId();
        abort_if($restricted !== null && $row->getAttribute('branch_id') !== $restricted, 404);

        return $row;
    }
}
