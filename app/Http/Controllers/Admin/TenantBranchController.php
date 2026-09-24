<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Tenancy\Actions\AddBranch;
use App\Domain\Tenancy\Actions\DeactivateBranch;
use App\Domain\Tenancy\Actions\ReactivateBranch;
use App\Domain\Tenancy\Actions\UpdateBranch;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBranchRequest;
use App\Http\Requests\Admin\UpdateBranchRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Branches of a tenant (admin). The branch is looked up inside its company only, so another company's
 * branch id is a 404.
 */
class TenantBranchController extends Controller
{
    public function store(StoreBranchRequest $request, Company $company, AddBranch $addBranch): RedirectResponse
    {
        $branch = $addBranch->handle($company, $request->details(), $request->integer('tills'));

        return back()->with('success', "Branch {$branch->name} added.");
    }

    public function update(UpdateBranchRequest $request, Company $company, string $branch, UpdateBranch $updateBranch): RedirectResponse
    {
        $model = $updateBranch->handle(self::find($company, $branch), $request->details());

        return back()->with('success', "Branch {$model->name} saved.");
    }

    public function deactivate(Company $company, string $branch, DeactivateBranch $deactivateBranch): RedirectResponse
    {
        $model = $deactivateBranch->handle(self::find($company, $branch));

        return back()->with('success', "Branch {$model->name} is deactivated.");
    }

    public function reactivate(Company $company, string $branch, ReactivateBranch $reactivateBranch): RedirectResponse
    {
        $model = $reactivateBranch->handle(self::find($company, $branch));

        return back()->with('success', "Branch {$model->name} is active again.");
    }

    public static function find(Company $company, string $branchId): Branch
    {
        return Branch::withoutCompanyScope()->whereBelongsTo($company)->findOrFail($branchId);
    }
}
