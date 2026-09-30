<?php

namespace App\Domain\PortalUsers\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Actions\CompanyOwners;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A portal user deletes their own account (module 4.1; the password is checked by the controller). Refused while they
 * are the last active owner of any business: a business must always keep an owner. Audited in each business they
 * belonged to.
 */
class DeleteOwnAccount
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user) {
            $memberships = DB::table('company_user')->where('user_id', $user->getKey())->lockForUpdate()->get(['company_id', 'role', 'is_active']);

            foreach ($memberships as $membership) {
                if ($membership->role !== CompanyRole::Owner->value || ! (bool) $membership->is_active) {
                    continue;
                }

                $company = Company::query()->withTrashed()->findOrFail($membership->company_id);

                if ($company->isCancelled() || $company->trashed()) {
                    continue;
                }

                try {
                    CompanyOwners::ensureAnotherOwner($company, $user, '');
                } catch (ValidationException) {
                    throw ValidationException::withMessages(['password' => "You are the only owner of {$company->name}. Make someone else an owner before you delete your account."]);
                }
            }

            foreach ($memberships as $membership) {
                $this->audit->handle('user.account_deleted', $user, ['role' => $membership->role], null, ['email' => $user->email], companyId: (string) $membership->company_id);
            }

            $user->delete();
        });
    }
}
