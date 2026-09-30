<?php

namespace App\Http\Controllers\App;

use App\Domain\Compliance\Data\ComplianceFilters;
use App\Domain\Compliance\Queries\AgeChecks;
use App\Domain\Compliance\Queries\ComplianceOverview;
use App\Domain\Compliance\Queries\DiaryChecks;
use App\Domain\Compliance\Queries\ExceptionReport;
use App\Domain\Compliance\Queries\IncidentList;
use App\Domain\Compliance\Queries\LicenceList;
use App\Domain\Compliance\Queries\RecallList;
use App\Domain\Compliance\Queries\TrainingList;
use App\Domain\Compliance\Support\ComplianceLookup;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\TillData\Models\IncidentReport;
use App\Domain\TillData\Models\ProductRecall;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Compliance\ComplianceFilterRequest;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Compliance of the tenant portal (module 5.7): age checks and refusals, incidents, staff training, diary checks,
 * licences held, product recalls and the exceptions report. Everything is the tills' (branch-owned, ownership.json)
 * and read only, except recalls (hub-owned: ProductRecallController writes them). A one-shop user sees only their
 * shop; recalls are company-wide.
 */
class ComplianceController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(ComplianceFilterRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/compliance/overview', $filters, ComplianceOverview::for($filters, CarbonImmutable::now()));
    }

    public function ageChecks(ComplianceFilterRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/compliance/age-checks', $filters, AgeChecks::for($request, $filters));
    }

    public function incidents(ComplianceFilterRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/compliance/incidents', $filters, IncidentList::for($request, $filters));
    }

    public function incident(string $incident): Response
    {
        $row = IncidentReport::query()->findOrFail($incident);
        $restricted = $this->tenancy->restrictedBranchId();
        abort_if($restricted !== null && $row->branch_id !== $restricted, 404);

        return Inertia::render('app/compliance/incident', IncidentList::show($row));
    }

    public function training(ComplianceFilterRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/compliance/training', $filters, TrainingList::for($request, $filters));
    }

    public function diary(ComplianceFilterRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/compliance/diary', $filters, DiaryChecks::for($request, $filters, CarbonImmutable::now()));
    }

    public function licences(ComplianceFilterRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/compliance/licences', $filters, LicenceList::for($request, $filters));
    }

    public function recalls(ComplianceFilterRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/compliance/recalls', $filters, [
            ...RecallList::for($request, $filters),
            'suppliers' => fn () => $this->canManage() ? RecallList::suppliers() : [],
            'productResults' => Inertia::optional(fn () => RecallList::products($request->string('q')->toString())),
        ]);
    }

    public function recall(ComplianceFilterRequest $request, string $recall): Response
    {
        $filters = $request->filters();

        return $this->page('app/compliance/recall', $filters, [
            ...RecallList::show(ProductRecall::query()->findOrFail($recall), $filters),
            'suppliers' => fn () => $this->canManage() ? RecallList::suppliers() : [],
            'productResults' => Inertia::optional(fn () => RecallList::products($request->string('q')->toString())),
        ]);
    }

    public function exceptions(ComplianceFilterRequest $request): Response
    {
        $filters = $request->filters();

        return $this->page('app/compliance/exceptions', $filters, ExceptionReport::for($request, $filters));
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function page(string $component, ComplianceFilters $filters, array $props): Response
    {
        return Inertia::render($component, [
            ...$props,
            'filters' => $filters->toArray(),
            'options' => ComplianceLookup::options($filters),
            'canManage' => $this->canManage(),
        ]);
    }

    /** Recalls go to every shop: `compliance.manage` and every shop (not a one-shop manager). */
    private function canManage(): bool
    {
        return $this->tenancy->can(Ability::ComplianceManage) && $this->tenancy->restrictedBranchId() === null;
    }
}
