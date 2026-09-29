<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Licensing\Actions\ClearLocalLicenceKey;
use App\Domain\Licensing\Models\LocalLicenceKey;
use App\Domain\Sync\Queries\CloudLinkList;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Module 2.8: the admin "Cloud link" screen (contract v1.4.1 §17.10): shops moving to the cloud with their upload
 * progress, and the local key register (tenants.view); clearing a register record needs licences.manage.
 */
class CloudLinkController extends Controller
{
    public const VIEWS = ['moves', 'keys', 'refused'];

    public function index(Request $request): Response
    {
        $view = in_array($request->query('view'), self::VIEWS, true) ? (string) $request->query('view') : 'moves';

        return Inertia::render('admin/cloud-link/index', [
            'view' => $view,
            'moves' => $view === 'moves' ? CloudLinkList::uploads($request) : null,
            'keys' => $view !== 'moves' ? CloudLinkList::localKeys($request, refusedOnly: $view === 'refused') : null,
            'summary' => CloudLinkList::summary(),
            'canClear' => $request->user('admin')?->hasAbility(AdminRole::LICENCES_MANAGE) ?? false,
        ]);
    }

    public function destroy(Request $request, LocalLicenceKey $localKey, ClearLocalLicenceKey $clear): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);
        $clear->handle($localKey, (string) $data['reason']);

        return back()->with('success', "Local key record for install code {$localKey->install_code} cleared. The next report is a first sighting.");
    }
}
