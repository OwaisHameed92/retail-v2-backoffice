<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes a member's role. The last active owner cannot be demoted.
 */
class ChangeCompanyUserRole
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, User $user, CompanyRole $role): void
    {
        DB::transaction(function () use ($company, $user, $role) {
            $membership = CompanyOwners::membership($company, $user);

            if ($membership === null) {
                throw ValidationException::withMessages(['user' => "{$user->name} is not a user of {$company->name}."]);
            }

            if ($membership->role === $role->value) {
                return;
            }

            if ($membership->role === CompanyRole::Owner->value && (bool) $membership->is_active) {
                CompanyOwners::ensureAnotherOwner($company, $user, "{$user->name} is the only owner. Make someone else an owner first.");
            }

            $company->users()->updateExistingPivot($user->getKey(), ['role' => $role->value]);

            $this->audit->handle('company.user_role_changed', $user, ['role' => $membership->role], ['role' => $role->value], [
                'email' => $user->email,
            ], companyId: $company->id);
        });
    }
}
