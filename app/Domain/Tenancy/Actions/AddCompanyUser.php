<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Gives a person access to a company's portal with a role.
 *
 * - New email: creates the user with an unusable random password and, after commit, emails the branded
 *   7-day "set your password" link (module 1.7's SendPasswordSetupLink, via ResendPasswordSetupLink).
 * - Existing user (e.g. already a member of another business): adds the membership; they keep their password.
 * - Inactive membership: reactivates it with the new role.
 */
class AddCompanyUser
{
    public function __construct(
        private readonly ResendPasswordSetupLink $sendSetupLink,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, string $name, string $email, CompanyRole $role): User
    {
        $email = Str::lower(trim($email));
        $name = trim($name);

        return DB::transaction(function () use ($company, $name, $email, $role) {
            $user = User::query()->where('email', $email)->first();
            $isNew = $user === null;

            if ($isNew) {
                if ($name === '') {
                    throw ValidationException::withMessages(['name' => 'Enter the person’s name.']);
                }

                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Str::password(40),
                ]);
            }

            $membership = CompanyOwners::membership($company, $user);

            if ($membership !== null && (bool) $membership->is_active) {
                throw ValidationException::withMessages(['email' => "{$email} is already a user of {$company->name}."]);
            }

            if ($membership !== null) {
                $company->users()->updateExistingPivot($user->getKey(), ['role' => $role->value, 'is_active' => true]);
            } else {
                $company->users()->attach($user->getKey(), ['role' => $role->value, 'is_active' => true]);
            }

            $this->audit->handle('company.user_added', $user, null, ['role' => $role->value], [
                'email' => $email,
                'new_account' => $isNew,
                'reactivated' => $membership !== null,
            ], companyId: $company->id);

            if ($isNew) {
                DB::afterCommit(fn () => $this->sendSetupLink->handle($user, $company));
            }

            return $user;
        });
    }
}
