<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Tenancy\Actions\ActivateCompany;
use App\Domain\Tenancy\Actions\CancelCompany;
use App\Domain\Tenancy\Actions\SuspendCompany;
use App\Domain\Tenancy\Actions\UnsuspendCompany;
use App\Domain\Tenancy\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TenantStatusReasonRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Status changes of a tenant. Authorised by the `can:update,company` route middleware.
 */
class TenantStatusController extends Controller
{
    public function activate(Company $company, ActivateCompany $activate): RedirectResponse
    {
        $activate->handle($company);

        return back()->with('success', "{$company->name} is now active.");
    }

    public function suspend(TenantStatusReasonRequest $request, Company $company, SuspendCompany $suspend): RedirectResponse
    {
        $suspend->handle($company, $request->reason());

        return back()->with('success', "{$company->name} is suspended. Its users now see an on-hold page.");
    }

    public function unsuspend(Company $company, UnsuspendCompany $unsuspend): RedirectResponse
    {
        $unsuspend->handle($company);

        return back()->with('success', "{$company->name} is no longer suspended.");
    }

    public function cancel(TenantStatusReasonRequest $request, Company $company, CancelCompany $cancel): RedirectResponse
    {
        $cancel->handle($company, $request->reason());

        return back()->with('success', "{$company->name} is cancelled. Its users can no longer sign in.");
    }
}
