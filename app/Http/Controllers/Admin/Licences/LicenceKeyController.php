<?php

namespace App\Http\Controllers\Admin\Licences;

use App\Domain\Licensing\Actions\ExtendActivateBy;
use App\Domain\Licensing\Actions\ResendLicenceKey;
use App\Domain\Mail\Support\MailFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ActivateByRequest;
use Illuminate\Http\RedirectResponse;

/**
 * An unused key's activate-by date and "Resend key e-mail" (module 1.11). `licences.manage` (route middleware).
 * The resent key is only in the owners' email, never in the reply.
 */
class LicenceKeyController extends Controller
{
    use FindsLicences;

    public function activateBy(ActivateByRequest $request, string $licence, ExtendActivateBy $extend): RedirectResponse
    {
        $model = $extend->handle($this->findLicence($licence), $request->until());

        return back()->with('success', 'The key can now be activated until '.MailFormat::date($model->activate_by ?? $request->until()).'.');
    }

    public function resend(string $licence, ResendLicenceKey $resend): RedirectResponse
    {
        $result = $resend->handle($this->findLicence($licence));
        $owners = $result['emailed'] === 1 ? 'the owner' : "{$result['emailed']} owners";

        return back()->with('success', "New key …{$result['issued']->licence->key_last4} emailed to {$owners}. The old key no longer works.");
    }
}
