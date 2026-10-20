<?php

namespace App\Http\Controllers\App;

use App\Domain\Handheld\Actions\CreateHandheldUser;
use App\Domain\Handheld\Actions\ResetHandheldPassword;
use App\Domain\Handheld\Actions\SetHandheldUserActive;
use App\Domain\Handheld\Actions\SignOutHandheldDevices;
use App\Domain\Handheld\Actions\UpdateHandheldUser;
use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Handheld\Queries\HandheldUsersPage;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Handheld\HandheldPasswordRequest;
use App\Http\Requests\App\Handheld\HandheldUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Handheld (PDA) users (`company.can:users.manage`): logins for the Android PDA app, one shop each. Add,
 * edit, switch off and on, set a new password (signs every device out) and sign devices out. Another business's
 * handheld user is simply not found (company scope).
 */
class HandheldUserController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(HandheldUsersPage $page): Response
    {
        return Inertia::render('app/handheld-users/index', $page->handle());
    }

    public function store(HandheldUserRequest $request, CreateHandheldUser $create): RedirectResponse
    {
        $handheld = $create->handle($request->handheld(), $this->actor($request));

        return back()->with('success', "{$handheld->name} can sign in to the handheld app now.");
    }

    public function update(HandheldUserRequest $request, string $handheld, UpdateHandheldUser $update): RedirectResponse
    {
        $saved = $update->handle($this->find($handheld), $request->handheld(), $this->actor($request));

        return back()->with('success', "{$saved->name} is updated. A new shop applies at the device’s next scan.");
    }

    public function deactivate(Request $request, string $handheld, SetHandheldUserActive $set): RedirectResponse
    {
        $saved = $set->handle($this->find($handheld), false, $this->actor($request));

        return back()->with('success', "{$saved->name} is switched off and signed out of every device.");
    }

    public function reactivate(Request $request, string $handheld, SetHandheldUserActive $set): RedirectResponse
    {
        $saved = $set->handle($this->find($handheld), true, $this->actor($request));

        return back()->with('success', "{$saved->name} can sign in to the handheld app again.");
    }

    public function password(HandheldPasswordRequest $request, string $handheld, ResetHandheldPassword $reset): RedirectResponse
    {
        $model = $this->find($handheld);
        $reset->handle($model, $request->password(), $this->actor($request));

        return back()->with('success', "{$model->name}’s password is changed. Every device signs in again with the new one.");
    }

    public function signOut(Request $request, string $handheld, SignOutHandheldDevices $signOut): RedirectResponse
    {
        $model = $this->find($handheld);
        $count = $signOut->handle($model, $this->actor($request));

        return back()->with('success', $count === 0 ? "{$model->name} was not signed in anywhere." : "{$model->name} is signed out of {$count} ".($count === 1 ? 'device' : 'devices').'.');
    }

    /** A handheld user of the current business (of the user's own shop for a one-shop user), else 404. */
    private function find(string $id): HandheldUser
    {
        $restricted = $this->tenancy->restrictedBranchId();

        return HandheldUser::query()->when($restricted !== null, fn ($query) => $query->where('branch_id', $restricted))->findOrFail($id);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : abort(403);
    }
}
