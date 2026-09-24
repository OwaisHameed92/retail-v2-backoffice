<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\Models\Admin;
use App\Domain\Tenancy\Actions\ImpersonateCompanyUser;
use App\Domain\Tenancy\Actions\StopImpersonating;
use App\Domain\Tenancy\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ImpersonateTenantRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Login as customer" start (admin area) and stop (reachable while impersonating).
 */
class ImpersonationController extends Controller
{
    public function store(ImpersonateTenantRequest $request, Company $company, ImpersonateCompanyUser $impersonate): RedirectResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin');
        $user = TenantUserController::find($company, $request->integer('user_id'));

        $impersonate->handle($admin, $company, $user, $request->session());

        return redirect()->route('app.dashboard');
    }

    public function destroy(Request $request, StopImpersonating $stop): RedirectResponse
    {
        $companyId = $stop->handle($request->session());

        if ($companyId === null) {
            return redirect()->route('admin.dashboard');
        }

        $company = Company::withTrashed()->find($companyId);

        return $company === null
            ? redirect()->route('admin.tenants.index')
            : redirect()->route('admin.tenants.show', $company)->with('success', 'You are back in the admin area.');
    }
}
