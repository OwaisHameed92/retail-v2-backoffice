<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\Impersonation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * "Login as customer": signs the admin in to the tenant portal as one of the company's users, in the same
 * session. The admin stays signed in to /admin but cannot reach it until they return. Not nestable.
 */
class ImpersonateCompanyUser
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws AuthorizationException|ValidationException
     */
    public function handle(Admin $admin, Company $company, User $user, Session $session): void
    {
        if (! $admin->hasAbility(AdminRole::TENANTS_MANAGE)) {
            throw new AuthorizationException('You are not allowed to log in as a customer.');
        }

        if (Impersonation::active($session)) {
            throw ValidationException::withMessages(['user_id' => 'You are already viewing the portal as a customer. Return to admin first.']);
        }

        if ($company->isCancelled()) {
            throw ValidationException::withMessages(['user_id' => "{$company->name} is cancelled, so its portal is closed."]);
        }

        $membership = CompanyOwners::membership($company, $user);

        if ($membership === null || ! (bool) $membership->is_active) {
            throw ValidationException::withMessages(['user_id' => "{$user->name} is not an active user of {$company->name}."]);
        }

        Auth::guard('web')->login($user);

        $session->put(Impersonation::SESSION_KEY, [
            'admin_id' => $admin->getKey(),
            'user_id' => $user->getKey(),
            'company_id' => $company->getKey(),
            'started_at' => now()->toIso8601String(),
        ]);
        $session->put(SwitchCurrentCompany::SESSION_KEY, $company->getKey());
        $session->forget(SwitchCurrentBranch::SESSION_KEY);

        $this->audit->handle('company.impersonation_started', $user, null, null, [
            'admin_email' => $admin->email,
            'user_email' => $user->email,
            'role' => $membership->role,
        ], actor: $admin, companyId: $company->id);
    }
}
