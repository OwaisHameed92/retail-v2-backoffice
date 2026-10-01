<?php

namespace App\Http\Controllers\App;

use App\Domain\Notifications\Actions\SaveAlertPreferences;
use App\Domain\Notifications\Queries\AlertSettingsPage;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\UpdateAlertPreferencesRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Notifications (module 7.8): the signed-in user's own alert emails in the current business. Every
 * member; the alert types follow their role.
 */
class NotificationSettingsController extends Controller
{
    public function edit(Request $request, CurrentCompany $tenancy): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('app/settings/notifications', AlertSettingsPage::for(
            $tenancy->require(), $user->id, $user->email, $tenancy->role() ?? CompanyRole::Staff, $tenancy->restrictedBranchId(),
        ));
    }

    public function update(UpdateAlertPreferencesRequest $request, CurrentCompany $tenancy, SaveAlertPreferences $save): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $save->handle($tenancy->require(), $user->id, $tenancy->role() ?? CompanyRole::Staff, $tenancy->restrictedBranchId(), $request->deliveries(), $request->shops());

        return back()->with('success', 'Notification settings saved.');
    }
}
