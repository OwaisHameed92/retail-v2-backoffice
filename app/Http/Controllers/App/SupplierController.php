<?php

namespace App\Http\Controllers\App;

use App\Domain\Setup\Actions\DeleteSetupRow;
use App\Domain\Setup\Actions\SaveSupplier;
use App\Domain\Setup\Queries\SupplierList;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\Supplier;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Setup\CompanyWideWriteRequest;
use App\Http\Requests\App\Setup\SupplierRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Suppliers on the tenant portal (module 4.5, `company.can:suppliers.manage`). Every change reaches every till at
 * its next sync. Queries run in the company scope: another business's supplier is simply not found.
 */
class SupplierController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(Request $request): Response
    {
        return Inertia::render('app/suppliers/index', SupplierList::index($request));
    }

    public function create(): Response
    {
        return Inertia::render('app/suppliers/form', SupplierList::form(null));
    }

    public function store(SupplierRequest $request, SaveSupplier $save): RedirectResponse
    {
        $supplier = $save->handle($this->tenancy->require(), null, $request->validated());

        return redirect()->route('app.suppliers.index')->with('success', "{$supplier->name} added. Your tills get it at their next sync.");
    }

    public function edit(string $supplier): Response
    {
        return Inertia::render('app/suppliers/form', SupplierList::form(Supplier::query()->findOrFail($supplier)));
    }

    public function update(SupplierRequest $request, string $supplier, SaveSupplier $save): RedirectResponse
    {
        $saved = $save->handle($this->tenancy->require(), Supplier::query()->findOrFail($supplier)->id, $request->validated());

        return redirect()->route('app.suppliers.index')->with('success', "{$saved->name} saved. Your tills get the change at their next sync.");
    }

    public function destroy(CompanyWideWriteRequest $request, string $supplier, DeleteSetupRow $delete): RedirectResponse
    {
        $row = Supplier::query()->findOrFail($supplier);
        $delete->handle($this->tenancy->require(), Supplier::class, $row->id);

        return redirect()->route('app.suppliers.index')->with('success', "{$row->name} removed. Past orders and invoices keep it.");
    }
}
