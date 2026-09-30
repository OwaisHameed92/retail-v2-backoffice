<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\Queries\Trading\TradingContext;
use App\Domain\Admin\Queries\Trading\TradingDashboard;
use App\Domain\Reporting\Enums\TradingCompare;
use App\Domain\Reporting\Enums\TradingPeriod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TradingDashboardRequest;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 3.2: the admin dashboard's Trading tab (`trading.view`): shop sales across every business, one business
 * or one shop. The figures are a deferred prop, so the page (filters, tabs) paints first with a skeleton.
 */
class TradingController extends Controller
{
    public function __invoke(TradingDashboardRequest $request, TradingDashboard $dashboard): Response
    {
        $context = TradingContext::for($request->input('company'), $request->input('branch'));
        $filters = $request->filters($context['company']['id'] ?? null, $context['branch']['id'] ?? null);

        return Inertia::render('admin/trading', [
            'filters' => $filters->toArray(),
            'context' => $context,
            'periods' => TradingPeriod::options(),
            'compares' => TradingCompare::options(),
            'trading' => Inertia::defer(fn () => $dashboard->for($filters)),
        ]);
    }
}
