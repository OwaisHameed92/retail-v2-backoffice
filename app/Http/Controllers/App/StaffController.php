<?php

namespace App\Http\Controllers\App;

use App\Domain\Staff\Actions\AssignStaffFob;
use App\Domain\Staff\Actions\DeleteStaffMember;
use App\Domain\Staff\Actions\SaveRolePermissions;
use App\Domain\Staff\Actions\SaveStaffMember;
use App\Domain\Staff\Actions\SetStaffPin;
use App\Domain\Staff\Queries\StaffScreens;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\TillRole;
use App\Domain\TillData\Models\TillUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Setup\CompanyWideWriteRequest;
use App\Http\Requests\App\Setup\RolePermissionsRequest;
use App\Http\Requests\App\Setup\StaffCredentialRequest;
use App\Http\Requests\App\Setup\StaffRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Till staff, their PINs and fobs, and the till roles' permissions on the tenant portal (module 4.5,
 * `company.can:staff.manage`). Every change reaches every till at its next sync. A PIN or fob code is never sent
 * back to the browser.
 */
class StaffController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(Request $request): Response
    {
        return Inertia::render('app/staff/index', StaffScreens::index($request));
    }

    public function create(): Response
    {
        return Inertia::render('app/staff/form', StaffScreens::form(null));
    }

    public function store(StaffRequest $request, SaveStaffMember $save): RedirectResponse
    {
        $member = $save->handle($this->tenancy->require(), null, $request->staff());

        return redirect()->route('app.staff.index')->with('success', "{$member->name} added. They can sign in to the tills after the next sync.");
    }

    public function edit(string $staff): Response
    {
        return Inertia::render('app/staff/form', StaffScreens::form(TillUser::query()->findOrFail($staff)));
    }

    public function update(StaffRequest $request, string $staff, SaveStaffMember $save): RedirectResponse
    {
        $member = $save->handle($this->tenancy->require(), TillUser::query()->findOrFail($staff)->id, $request->staff());

        return redirect()->route('app.staff.edit', $member->id)->with('success', "{$member->name} saved. Your tills get the change at their next sync.");
    }

    public function pin(StaffCredentialRequest $request, string $staff, SetStaffPin $set): RedirectResponse
    {
        $member = $set->handle($this->tenancy->require(), TillUser::query()->findOrFail($staff)->id, $request->secret());

        return back()->with('success', "New PIN set for {$member->name}. It works on the tills after their next sync.");
    }

    public function fob(StaffCredentialRequest $request, string $staff, AssignStaffFob $assign): RedirectResponse
    {
        $member = $assign->handle($this->tenancy->require(), TillUser::query()->findOrFail($staff)->id, $request->secret());

        return back()->with('success', "Fob saved for {$member->name}. It works on the tills after their next sync.");
    }

    public function destroy(CompanyWideWriteRequest $request, string $staff, DeleteStaffMember $delete): RedirectResponse
    {
        $member = TillUser::query()->findOrFail($staff);
        $delete->handle($this->tenancy->require(), $member->id);

        return redirect()->route('app.staff.index')->with('success', "{$member->name} removed. They can no longer sign in once the tills sync.");
    }

    public function roles(Request $request): Response
    {
        return Inertia::render('app/staff/roles', StaffScreens::roles($request));
    }

    public function updateRole(RolePermissionsRequest $request, string $role, SaveRolePermissions $save): RedirectResponse
    {
        $model = TillRole::query()->findOrFail($role);
        $changes = $save->handle($this->tenancy->require(), $model->id, $request->permissions());
        $count = count($changes['granted']) + count($changes['removed']);

        return redirect()->route('app.staff.roles', ['role' => $model->id])->with('success', $count === 0
            ? 'Nothing changed.'
            : "{$model->name} permissions saved. Your tills get the change at their next sync.");
    }
}
