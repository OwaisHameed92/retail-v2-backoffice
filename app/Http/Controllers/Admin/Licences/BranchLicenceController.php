<?php

namespace App\Http\Controllers\Admin\Licences;

use App\Domain\Licensing\Actions\UpdateBranchLicence;
use App\Domain\Licensing\Actions\UpdateBranchLimits;
use App\Domain\Tenancy\Models\Company;
use App\Http\Controllers\Admin\TenantBranchController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BranchLicenceRequest;
use App\Http\Requests\Admin\BranchLimitsRequest;
use Illuminate\Http\RedirectResponse;

/**
 * The licence form on the tenant page (module 1.11): a branch's licence settings and the company's branch
 * limits. `licences.manage` (route middleware). The branch is looked up inside its company only.
 */
class BranchLicenceController extends Controller
{
    public function update(BranchLicenceRequest $request, Company $company, string $branch, UpdateBranchLicence $update): RedirectResponse
    {
        $model = $update->handle(TenantBranchController::find($company, $branch), $request->settings());

        return back()->with('success', "Licence settings for {$model->name} saved. Its tills get the new key details at their next check-in.");
    }

    public function limits(BranchLimitsRequest $request, Company $company, UpdateBranchLimits $update): RedirectResponse
    {
        $model = $update->handle($company, $request->boolean('multi_branch'), $request->integer('max_branches', 1));

        return back()->with('success', $model->multi_branch
            ? "{$model->name} may run up to {$model->max_branches} ".($model->max_branches === 1 ? 'branch' : 'branches').'.'
            : "{$model->name} is licensed for one branch.");
    }
}
