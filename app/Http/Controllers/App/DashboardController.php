<?php

namespace App\Http\Controllers\App;

use App\Domain\Ai\MorningSummary\Queries\YesterdayAtAGlance;
use App\Domain\Reporting\Dashboard\BusinessContext;
use App\Domain\Reporting\Dashboard\BusinessDashboard;
use App\Domain\Reporting\Dashboard\ShopFreshness;
use App\Domain\Reporting\Enums\TradingCompare;
use App\Domain\Reporting\Enums\TradingPeriod;
use App\Domain\Tenancy\Actions\ResolveCurrentBranch;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\TillHealth\Queries\ShopsStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\BusinessDashboardRequest;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant dashboard: "Shops and tills" (module 2.7, `dashboard.view`) and the business dashboard (module 3.3,
 * `reports.view`): sales of all shops or the switcher's shop (a one-shop user: always theirs), one till optional.
 * The figures are a deferred prop, so the page paints first with a skeleton; without `reports.view` there are none.
 * "Yesterday at a glance" (module 6.3, the morning summary) is its own deferred group.
 */
class DashboardController extends Controller
{
    public function __invoke(BusinessDashboardRequest $request, CurrentCompany $current, ResolveCurrentBranch $switcher, BusinessDashboard $dashboard, YesterdayAtAGlance $glance): Response
    {
        $company = $current->require();
        $context = BusinessContext::for($current, $switcher->handle($request->session()), $request->input('till'));
        $branchId = $context['branch']['id'] ?? null;
        $canSales = $current->can(Ability::ReportsView);
        $filters = $request->filters($current, $branchId, $context['till']['id'] ?? null);

        return Inertia::render('app/dashboard', [
            // Module 2.7: shops and tills status (read only), for the chosen shop or all.
            'status' => $current->can(Ability::DashboardView) ? ShopsStatus::for($company, $branchId, CarbonImmutable::now()) : null,
            'canSales' => $canSales,
            'filters' => $filters->toArray(),
            'context' => $context,
            'periods' => TradingPeriod::options(),
            'compares' => TradingCompare::options(),
            'sales' => $canSales
                ? Inertia::defer(fn () => [...$dashboard->for($filters), 'freshness' => ShopFreshness::for($filters->scope(), CarbonImmutable::now())])
                : null,
            'yesterday' => $canSales
                ? Inertia::defer(fn () => $glance->for($company, (int) $request->user()?->getKey(), $current->role() ?? CompanyRole::Staff, $branchId, CarbonImmutable::now()), 'yesterday')
                : null,
        ]);
    }
}
