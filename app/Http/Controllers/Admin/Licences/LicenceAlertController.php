<?php

namespace App\Http\Controllers\Admin\Licences;

use App\Domain\Admin\Models\Admin;
use App\Domain\Licensing\Actions\ResolveLicenceAlert;
use App\Domain\Licensing\Models\LicenceAlert;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Licence API alerts on the admin licence page (module 1.5). Needs `licences.manage` (route middleware).
 * The alert is looked up inside the licence from the URL, so a mismatched pair is a 404.
 */
class LicenceAlertController extends Controller
{
    use FindsLicences;

    public function resolve(Request $request, string $licence, string $alert, ResolveLicenceAlert $resolve): RedirectResponse
    {
        $model = $this->findLicence($licence);
        $row = LicenceAlert::withoutCompanyScope()->where('licence_id', $model->id)->findOrFail($alert);
        $admin = $request->user('admin');

        $resolve->handle($row, $admin instanceof Admin ? $admin : null);

        return back()->with('success', 'Alert marked as resolved.');
    }
}
