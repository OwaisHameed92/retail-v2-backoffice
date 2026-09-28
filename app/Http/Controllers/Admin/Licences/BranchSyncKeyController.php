<?php

namespace App\Http\Controllers\Admin\Licences;

use App\Domain\Sync\Actions\IssueSyncKey;
use App\Domain\Sync\Actions\RequestSyncKeyRotation;
use App\Domain\Sync\Actions\RevokeSyncKeys;
use App\Domain\Sync\Enums\SyncKeySource;
use App\Domain\Sync\Support\SyncKeySecret;
use App\Domain\Tenancy\Models\Company;
use App\Http\Controllers\Admin\TenantBranchController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A branch's sync key on the admin tenant page (module 2.1), `licences.manage` (route middleware). The branch is
 * looked up inside its company only. Generate answers JSON: the key exists only in that reply (shown once, for
 * the till's Settings → Cloud sync → Connect).
 */
class BranchSyncKeyController extends Controller
{
    public function generate(Request $request, Company $company, string $branch, IssueSyncKey $issue): JsonResponse
    {
        $model = TenantBranchController::find($company, $branch);
        $key = $issue->handle($model, SyncKeySource::Admin, $request->user('admin'));

        return response()->json([
            'message' => "New sync key for {$model->name}. Any older key keeps working for 7 days.",
            'key' => $key,
            'last4' => SyncKeySecret::last4($key),
            'branchName' => $model->name,
            'businessName' => $company->name,
        ])->withHeaders(['Cache-Control' => 'no-store']);
    }

    public function rotate(Request $request, Company $company, string $branch, RequestSyncKeyRotation $rotate): RedirectResponse
    {
        $model = TenantBranchController::find($company, $branch);
        $rotate->handle($model, $request->user('admin'));

        return back()->with('success', "{$model->name}'s main till gets a new sync key at its next licence check.");
    }

    public function revoke(Request $request, Company $company, string $branch, RevokeSyncKeys $revoke): RedirectResponse
    {
        $model = TenantBranchController::find($company, $branch);
        $revoke->handle($model, $request->user('admin'));

        return back()->with('success', "{$model->name}'s sync key is revoked. Its till stops syncing now.");
    }
}
