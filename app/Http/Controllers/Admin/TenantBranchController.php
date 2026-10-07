<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Billing\Actions\ChargeAddedTills;
use App\Domain\Licensing\Data\IssuedLicence;
use App\Domain\Licensing\Data\LicenceData;
use App\Domain\Licensing\Support\IssuedKeys;
use App\Domain\Tenancy\Actions\AddBranch;
use App\Domain\Tenancy\Actions\DeactivateBranch;
use App\Domain\Tenancy\Actions\ReactivateBranch;
use App\Domain\Tenancy\Actions\UpdateBranch;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBranchRequest;
use App\Http\Requests\Admin\UpdateBranchRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Branches of a tenant (admin). The branch is looked up inside its company only, so another company's
 * branch id is a 404.
 */
class TenantBranchController extends Controller
{
    /**
     * Adds a branch with its first tills; their licences are issued with them (module 1.3). Asked for JSON (the
     * admin dialog), the reply carries the new plain keys for the one-time "Licence key created" dialog.
     */
    public function store(StoreBranchRequest $request, Company $company, AddBranch $addBranch, IssuedKeys $issuedKeys, ChargeAddedTills $chargeAddedTills): RedirectResponse|JsonResponse
    {
        $branch = $addBranch->handle($company, $request->details(), $request->integer('tills'));
        $issued = $issuedKeys->pullForCompany($company->id);
        $keys = LicenceData::issuedKeys($issued);
        // P11: a per-till setup fee plan invoices the added tills (they stay on trial until that is paid).
        $invoice = $chargeAddedTills->handle($company, array_map(fn (IssuedLicence $item) => $item->licence, $issued), $request->tillSetupFee());
        $fee = $invoice !== null ? " Setup fee invoice {$invoice->number} raised: the new tills stay on their trial until it is paid." : '';

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            $message = $keys === [] && $request->integer('tills') > 0
                ? "Branch {$branch->name} added. Its tills have no licences yet: create an active plan, then issue them."
                : "Branch {$branch->name} added.{$fee}";

            return response()->json(['message' => $message, 'keys' => $keys])->withHeaders(['Cache-Control' => 'no-store']);
        }

        return back()->with('success', "Branch {$branch->name} added.{$fee}");
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
