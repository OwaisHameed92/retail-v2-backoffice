<?php

namespace App\Http\Controllers\App;

use App\Domain\Stock\Actions\SetProductStockLevels;
use App\Domain\Stock\Queries\ExpiryList;
use App\Domain\Stock\Queries\MovementList;
use App\Domain\Stock\Queries\ProductStock;
use App\Domain\Stock\Queries\StockNames;
use App\Domain\Stock\Queries\StockOnHand;
use App\Domain\Stock\Queries\StockValuation;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\TillData\Models\Product;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Stock\StockFilterRequest;
use App\Http\Requests\App\Stock\StockLevelsRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Stock of the tenant portal (module 5.1). The tills own stock (BranchProduct, movements, layers, stock takes, date
 * checks: branch-owned), so these screens read it; the only write is a product's own stock levels (Product is
 * hub-owned). `stock.view` to look, `stock.manage` to set levels. A one-shop user sees only their shop.
 */
class StockController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(StockFilterRequest $request, StockOnHand $stock): Response
    {
        $filters = $request->filters();
        $companyId = (string) $this->tenancy->id();
        $page = $stock->page($companyId, $filters, $request->pageNumber(), $request->perPage());

        return Inertia::render('app/stock/index', [
            'filters' => $filters->toArray(),
            'summary' => $stock->summary($companyId, $filters),
            'stock' => ['data' => $page['rows'], 'meta' => ['page' => $request->pageNumber(), 'perPage' => $request->perPage(), 'total' => $page['total']]],
            'options' => StockNames::options($filters),
            'hasStock' => $page['total'] > 0 || $stock->hasAny($companyId),
        ]);
    }

    public function product(StockFilterRequest $request, string $product, ProductStock $query): Response
    {
        $model = Product::query()->findOrFail($product);

        return Inertia::render('app/stock/product', [
            ...$query->for($model, $request->filters()),
            'filters' => $request->filters()->toArray(),
            'canManage' => $this->tenancy->can(Ability::StockManage),
        ]);
    }

    public function updateLevels(StockLevelsRequest $request, string $product, SetProductStockLevels $action): RedirectResponse
    {
        $changed = $action->handle(Product::query()->findOrFail($product), $request->levels());

        return back()->with('success', $changed === [] ? 'Nothing to change.' : 'Stock levels saved. Your tills get them at their next sync.');
    }

    public function movements(StockFilterRequest $request): Response
    {
        $filters = $request->filters();

        return Inertia::render('app/stock/movements', [
            ...MovementList::for($request, $filters),
            'filters' => $filters->toArray(),
            'options' => StockNames::options($filters),
            'canViewSales' => $this->tenancy->can(Ability::SalesView),
        ]);
    }

    public function valuation(StockFilterRequest $request, StockValuation $valuation): Response
    {
        $filters = $request->filters();

        return Inertia::render('app/stock/valuation', [
            ...$valuation->for((string) $this->tenancy->id(), $filters),
            'filters' => $filters->toArray(),
            'options' => StockNames::options($filters),
        ]);
    }

    public function expiry(StockFilterRequest $request): Response
    {
        $filters = $request->filters();

        return Inertia::render('app/stock/expiry', [
            ...ExpiryList::for($filters, $request->pageNumber(), $request->perPage()),
            'filters' => $filters->toArray(),
            'options' => StockNames::options($filters),
        ]);
    }
}
