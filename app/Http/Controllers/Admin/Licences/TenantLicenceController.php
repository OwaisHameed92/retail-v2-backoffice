<?php

namespace App\Http\Controllers\Admin\Licences;

use App\Domain\Licensing\Actions\ChangeCompanyPlan;
use App\Domain\Licensing\Actions\IssueLicence;
use App\Domain\Licensing\Actions\IssueMissingLicences;
use App\Domain\Licensing\Actions\RenewCompanyLicences;
use App\Domain\Licensing\Data\LicenceData;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeLicencePlanRequest;
use App\Http\Requests\Admin\RenewLicenceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Licence actions on a whole tenant (module 1.3), from the tenant page. `licences.manage` (route middleware).
 * Issuing answers with JSON: the new keys exist only in that reply.
 */
class TenantLicenceController extends Controller
{
    public function issueMissing(Company $company, IssueMissingLicences $issueMissing): JsonResponse
    {
        $issued = $issueMissing->handle($company);

        return response()->json([
            'message' => $issued->isEmpty() ? 'Every active till already has a licence.' : ($issued->count() === 1 ? '1 licence issued.' : "{$issued->count()} licences issued."),
            'keys' => LicenceData::issuedKeys($issued->items),
        ])->withHeaders(['Cache-Control' => 'no-store']);
    }

    public function issueForRegister(Company $company, string $register, IssueLicence $issueLicence): JsonResponse
    {
        $till = Register::withoutCompanyScope()->whereBelongsTo($company)->findOrFail($register);
        $issued = $issueLicence->handle($till);

        return response()->json([
            'message' => "Licence issued for {$till->name}.",
            'keys' => [LicenceData::issuedKey($issued)],
        ])->withHeaders(['Cache-Control' => 'no-store']);
    }

    public function renewAll(RenewLicenceRequest $request, Company $company, RenewCompanyLicences $renewAll): RedirectResponse
    {
        $result = $renewAll->handle($company, $request->term(), $request->notify());
        $count = $result->count() === 1 ? '1 licence' : "{$result->count()} licences";
        $until = MailFormat::date($result->expiresAt);

        return back()->with('success', "Renewed {$count} until {$until}.".($result->ownersEmailed > 0 ? ' The owner has been emailed.' : ''));
    }

    public function changePlan(ChangeLicencePlanRequest $request, Company $company, ChangeCompanyPlan $changePlan): RedirectResponse
    {
        $plan = $request->plan();
        $moved = $changePlan->handle($company, $plan, $request->boolean('apply_to_licences'));

        return back()->with('success', "New tills get {$plan->name}.".($moved > 0 ? ' '.($moved === 1 ? '1 licence' : "{$moved} licences").' moved to it.' : ''));
    }
}
