<?php

namespace App\Http\Controllers\App;

use App\Domain\Cash\Support\CashLookup;
use App\Domain\Reporting\Reports\ReportCsv;
use App\Domain\StaffTime\Data\TimeFilters;
use App\Domain\StaffTime\Queries\ClockList;
use App\Domain\StaffTime\Queries\RotaWeek;
use App\Domain\StaffTime\Queries\Timesheets;
use App\Domain\StaffTime\Support\PayrollCsv;
use App\Domain\StaffTime\Support\TimeLookup;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\StaffTime\StaffTimeRequest;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Staff time of the tenant portal (module 5.6), read only: clock events, rota shifts, timesheet approvals and wage
 * rates are all the tills' (branch-owned, ownership.json). Clock events, timesheets with a payroll CSV, and the rota
 * week. A one-shop user sees only their shop's staff and hours.
 */
class StaffTimeController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function clock(StaffTimeRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/staff/time/clock', $filters, ClockList::for($filters, $request->page(), $request->perPage()));
    }

    public function timesheets(StaffTimeRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/staff/time/timesheets', $filters, Timesheets::for($filters, $request->page(), $request->perPage()));
    }

    public function rota(StaffTimeRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/staff/time/rota', $filters, RotaWeek::for($filters, $request->rotaWeek()));
    }

    public function export(StaffTimeRequest $request): StreamedResponse
    {
        $filters = $request->filters();
        $shop = $filters->shop === null ? 'Every shop' : (CashLookup::shops([$filters->shop])[$filters->shop] ?? 'Unknown shop');
        $lines = PayrollCsv::lines(Timesheets::rows($filters), $filters, (string) $this->tenancy->require()->name, $shop);

        return response()->streamDownload(fn () => ReportCsv::write($lines), "timesheets-{$filters->from}-to-{$filters->to}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function page(string $component, TimeFilters $filters, array $props): Response
    {
        return Inertia::render($component, [...$props, 'filters' => $filters->toArray(), 'options' => TimeLookup::options($filters)]);
    }
}
