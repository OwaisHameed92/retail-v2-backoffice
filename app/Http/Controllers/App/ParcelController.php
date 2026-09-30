<?php

namespace App\Http\Controllers\App;

use App\Domain\Cash\Support\CashLookup;
use App\Domain\Parcels\Queries\ParcelActivity;
use App\Domain\Pharmacy\Support\ServiceModules;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Cash\CashFilterRequest;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Parcels on the tenant portal (module 5.10, `company.can:parcels.view`), only for businesses that take parcels
 * (ServiceModules; else 404). Read only: parcels and carriers are the shops' own (branch-owned). A one-shop user sees
 * their shop only.
 */
class ParcelController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(CashFilterRequest $request): Response
    {
        abort_unless(ServiceModules::parcels($this->tenancy->require()), 404);
        $filters = $request->filters();

        return Inertia::render('app/parcels/index', [
            ...ParcelActivity::for($request, $filters, ParcelActivity::only($request)),
            'filters' => $filters->toArray(),
            'options' => CashLookup::options($filters),
        ]);
    }
}
