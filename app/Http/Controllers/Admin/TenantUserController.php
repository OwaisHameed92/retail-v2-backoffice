<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Admin\Models\Admin;
use App\Domain\Security\Actions\ResetPortalUserTwoFactor;
use App\Domain\Tenancy\Actions\AddCompanyUser;
use App\Domain\Tenancy\Actions\ChangeCompanyUserRole;
use App\Domain\Tenancy\Actions\RemoveCompanyUser;
use App\Domain\Tenancy\Actions\ResendPasswordSetupLink;
use App\Domain\Tenancy\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreTenantUserRequest;
use App\Http\Requests\Admin\UpdateTenantUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Portal users of a tenant (admin). Users are looked up among the company's members only.
 */
class TenantUserController extends Controller
{
    public function store(StoreTenantUserRequest $request, Company $company, AddCompanyUser $addUser): RedirectResponse
    {
        $user = $addUser->handle($company, (string) $request->input('name'), (string) $request->input('email'), $request->role());

        return back()->with('success', "{$user->email} can now use the {$company->name} portal.");
    }

    public function update(UpdateTenantUserRequest $request, Company $company, int $user, ChangeCompanyUserRole $changeRole): RedirectResponse
    {
        $member = self::find($company, $user);
        $changeRole->handle($company, $member, $request->role());

        return back()->with('success', "{$member->name} is now {$request->role()->label()}.");
    }

    public function destroy(Company $company, int $user, RemoveCompanyUser $removeUser): RedirectResponse
    {
        $member = self::find($company, $user);
        $removeUser->handle($company, $member);

        return back()->with('success', "{$member->name} no longer has access to {$company->name}.");
    }

    public function sendPasswordLink(Company $company, int $user, ResendPasswordSetupLink $sendLink): RedirectResponse
    {
        $member = self::find($company, $user);
        $sendLink->handle($member, $company);

        return back()->with('success', "We emailed {$member->email} a link to set their password.");
    }

    /** Lost phone and recovery codes: the user signs in with their password again (module 7: two-factor). */
    public function resetTwoFactor(Request $request, Company $company, int $user, ResetPortalUserTwoFactor $reset): RedirectResponse
    {
        $member = self::find($company, $user);
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        $reset->handle($member, $company, $admin);

        return back()->with('success', "Two-factor sign-in reset for {$member->name}.");
    }

    public static function find(Company $company, int $userId): User
    {
        return $company->users()->whereKey($userId)->firstOrFail();
    }
}
