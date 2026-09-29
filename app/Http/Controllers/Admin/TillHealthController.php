<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillHealth\Enums\TillState;
use App\Domain\TillHealth\Queries\TillHealthList;
use App\Domain\TillHealth\Queries\TillHealthSummary;
use App\Domain\TillHealth\Support\HealthThresholds;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 2.7: the admin Till health list across every business (tenants.view). Reads the stored health rows,
 * refreshed every 5 minutes by `till-health:refresh`.
 */
class TillHealthController extends Controller
{
    public function index(Request $request): Response
    {
        $filter = in_array($request->query('filter'), TillHealthList::FILTERS, true) ? (string) $request->query('filter') : null;
        $state = TillState::tryFrom((string) $request->query('state'));
        $companyId = Ulid::isValid((string) $request->query('company')) ? (string) $request->query('company') : null;
        $company = $companyId === null ? null : Company::query()->withTrashed()->find($companyId, ['id', 'name']);

        return Inertia::render('admin/till-health/index', [
            'tills' => TillHealthList::paginate($request, $filter, $state, $company?->id),
            'summary' => TillHealthSummary::compute($company?->id),
            'filters' => [
                'filter' => $filter,
                'state' => $state?->value,
                'company' => $company === null ? null : ['id' => $company->id, 'name' => $company->name],
            ],
            'states' => TillState::options(),
            'thresholds' => HealthThresholds::fromConfig()->toArray(),
        ]);
    }
}
