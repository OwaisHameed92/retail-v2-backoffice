<?php

namespace App\Http\Controllers\App;

use App\Domain\Stock\Queries\StockNames;
use App\Domain\Stock\Queries\StockTakes;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\StockTake;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Stock\StockFilterRequest;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tills' stock takes (module 5.1), read only: `StockTake` is branch-owned (ownership.json), so a count is
 * started, counted and approved on the till; the portal shows the counts and their variances. `stock.view`; a
 * one-shop user sees only their shop's stock takes.
 */
class StockTakeController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(StockFilterRequest $request): Response
    {
        $filters = $request->filters();
        $status = in_array($request->query('status'), StockTakes::STATUSES, true) ? (string) $request->query('status') : null;

        return Inertia::render('app/stock/takes', [
            'takes' => StockTakes::page($filters, $status, $request->pageNumber(), $request->perPage(25)),
            'filters' => [...$filters->toArray(), 'takeStatus' => $status],
            'options' => StockNames::options($filters),
        ]);
    }

    public function show(StockFilterRequest $request, string $take): Response
    {
        $model = StockTake::query()->findOrFail($take);
        $restricted = $this->tenancy->restrictedBranchId();
        abort_if($restricted !== null && $model->branch_id !== $restricted, 404);

        return Inertia::render('app/stock/take', [
            ...StockTakes::detail($model, $request->query('view') === 'variances', $request->pageNumber(), $request->perPage(100)),
            'view' => $request->query('view') === 'variances' ? 'variances' : 'all',
        ]);
    }
}
