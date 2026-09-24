<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Removes a person's access to a company. Their user account stays (they may belong to other businesses);
 * with no memberships left they cannot sign in. The last active owner cannot be removed.
 */
class RemoveCompanyUser
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, User $user): void
    {
        DB::transaction(function () use ($company, $user) {
            $membership = CompanyOwners::membership($company, $user);

            if ($membership === null) {
                throw ValidationException::withMessages(['user' => "{$user->name} is not a user of {$company->name}."]);
            }

            if ($membership->role === CompanyRole::Owner->value && (bool) $membership->is_active) {
                CompanyOwners::ensureAnotherOwner($company, $user, "{$user->name} is the only owner and cannot be removed. Make someone else an owner first.");
            }

            $company->users()->detach($user->getKey());

            $this->audit->handle('company.user_removed', $user, ['role' => $membership->role, 'is_active' => (bool) $membership->is_active], null, [
                'email' => $user->email,
            ], companyId: $company->id);
        });
    }
}
