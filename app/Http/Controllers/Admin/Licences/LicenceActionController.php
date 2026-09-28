<?php

namespace App\Http\Controllers\Admin\Licences;

use App\Domain\Licensing\Actions\ChangeLicencePlan;
use App\Domain\Licensing\Actions\ReissueKey;
use App\Domain\Licensing\Actions\ReleaseDevice;
use App\Domain\Licensing\Actions\RenewLicence;
use App\Domain\Licensing\Actions\RevokeLicence;
use App\Domain\Licensing\Actions\SuspendLicence;
use App\Domain\Licensing\Actions\UnsuspendLicence;
use App\Domain\Licensing\Data\LicenceData;
use App\Domain\Mail\Support\MailFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangeLicencePlanRequest;
use App\Http\Requests\Admin\LicenceReasonRequest;
use App\Http\Requests\Admin\RenewLicenceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Changes to one licence (module 1.3). All need `licences.manage` (route middleware).
 * Reissue answers with JSON: the new key exists only in that reply.
 */
class LicenceActionController extends Controller
{
    use FindsLicences;

    public function renew(RenewLicenceRequest $request, string $licence, RenewLicence $renew): RedirectResponse
    {
        $result = $renew->handle($this->findLicence($licence), $request->term(), $request->notify());
        $until = MailFormat::date($result->expiresAt);

        return back()->with('success', $result->ownersEmailed > 0
            ? "Renewed until {$until}. The owner has been emailed."
            : "Renewed until {$until}.");
    }

    public function changePlan(ChangeLicencePlanRequest $request, string $licence, ChangeLicencePlan $changePlan): RedirectResponse
    {
        $plan = $request->plan();
        $result = $changePlan->handle($this->findLicence($licence), $plan);

        return back()->with('success', $result->changed ? "Moved to {$plan->name}. The till gets the new features at its next check-in." : "Already on {$plan->name}.");
    }

    public function release(string $licence, ReleaseDevice $release): RedirectResponse
    {
        $release->handle($this->findLicence($licence));

        return back()->with('success', 'Key released. The old PC locks at its next check-in; the key can now be activated on another PC.');
    }

    public function reissue(string $licence, ReissueKey $reissue): JsonResponse
    {
        $issued = $reissue->handle($this->findLicence($licence));

        return response()->json([
            'message' => 'New key created. The old key no longer works.',
            'keys' => [LicenceData::issuedKey($issued)],
        ])->withHeaders(['Cache-Control' => 'no-store']);
    }

    public function suspend(LicenceReasonRequest $request, string $licence, SuspendLicence $suspend): RedirectResponse
    {
        $suspend->handle($this->findLicence($licence), $request->reason());

        return back()->with('success', 'Licence suspended. The till stops trading at its next check-in.');
    }

    public function unsuspend(string $licence, UnsuspendLicence $unsuspend): RedirectResponse
    {
        $result = $unsuspend->handle($this->findLicence($licence));

        return back()->with('success', "Suspension lifted. The licence is {$result->to->label()} again.");
    }

    public function revoke(LicenceReasonRequest $request, string $licence, RevokeLicence $revoke): RedirectResponse
    {
        $revoke->handle($this->findLicence($licence), $request->reason());

        return back()->with('success', 'Licence revoked. The key will never work again.');
    }
}
