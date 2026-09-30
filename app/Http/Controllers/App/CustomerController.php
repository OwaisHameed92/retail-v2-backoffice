<?php

namespace App\Http\Controllers\App;

use App\Domain\Customers\Actions\SaveCustomer;
use App\Domain\Customers\Actions\SendCustomerStatement;
use App\Domain\Customers\Queries\CustomerDetail;
use App\Domain\Customers\Queries\CustomerList;
use App\Domain\Customers\Queries\CustomerStatement;
use App\Domain\Customers\Support\StatementPdf;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\Customer;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Customers\CustomerRequest;
use App\Http\Requests\App\Customers\StatementRequest;
use App\Http\Requests\App\Setup\CompanyWideWriteRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Customers of the tenant portal (module 4.4): list and customer page (`customers.view`), add / edit details
 * (`customers.manage`, not a one-shop user), statements on screen, as PDF and by email. Balances and points come from
 * the ledger; the portal never writes ledger rows (contract §10.1 rule 7). Everything runs in the current company's
 * scope: another business's customer is simply not found.
 */
class CustomerController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(Request $request): Response
    {
        return Inertia::render('app/customers/index', CustomerList::for($request));
    }

    public function create(CompanyWideWriteRequest $request): Response
    {
        return Inertia::render('app/customers/create');
    }

    public function store(CustomerRequest $request, SaveCustomer $save): RedirectResponse
    {
        $customer = $save->handle($this->tenancy->require(), null, $request->validated());

        return redirect()->route('app.customers.show', $customer->id)
            ->with('success', "{$customer->name} added. Your tills get them at their next sync.");
    }

    public function show(Request $request, string $customer): Response
    {
        return Inertia::render('app/customers/show', CustomerDetail::for($request, Customer::query()->findOrFail($customer)));
    }

    public function update(CustomerRequest $request, string $customer, SaveCustomer $save): RedirectResponse
    {
        $model = Customer::query()->findOrFail($customer);
        $before = $model->row_version;
        $saved = $save->handle($this->tenancy->require(), $model->id, $request->validated());

        return back()->with('success', $saved->row_version === $before ? 'Nothing to save: no changes.' : 'Customer saved. Your tills get the change at their next sync.');
    }

    public function statement(StatementRequest $request, string $customer): Response
    {
        $model = Customer::query()->findOrFail($customer);
        [$from, $to] = $request->period();

        return Inertia::render('app/customers/statement', [
            'statement' => CustomerStatement::for($this->tenancy->require(), $model, $from, $to),
            'canEmail' => CustomerDetail::canEmail($model),
        ]);
    }

    public function statementPdf(StatementRequest $request, string $customer, StatementPdf $pdf): HttpResponse
    {
        $model = Customer::query()->findOrFail($customer);
        [$from, $to] = $request->period();

        return response($pdf->render(CustomerStatement::for($this->tenancy->require(), $model, $from, $to)), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.CustomerStatement::filename($model, $from, $to).'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function emailStatement(StatementRequest $request, string $customer, SendCustomerStatement $send): RedirectResponse
    {
        [$from, $to] = $request->period();
        $model = $send->handle($this->tenancy->require(), Customer::query()->findOrFail($customer)->id, $from, $to);

        return back()->with('success', "Statement emailed to {$model->name}.");
    }
}
