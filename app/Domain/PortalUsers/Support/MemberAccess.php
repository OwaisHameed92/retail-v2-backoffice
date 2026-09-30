<?php

namespace App\Domain\PortalUsers\Support;

use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rules shared by the portal user actions (module 4.1): the membership row, the one-shop rule and "not yourself".
 */
final class MemberAccess
{
    /**
     * The user's membership of the company, locked for the rest of the transaction.
     *
     * @return object{role: string, branch_id: string|null, is_active: int|bool}|null
     */
    public static function membership(Company $company, User $user): ?object
    {
        /** @var object{role: string, branch_id: string|null, is_active: int|bool}|null $row */
        $row = DB::table('company_user')
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->lockForUpdate()
            ->first(['role', 'branch_id', 'is_active']);

        return $row;
    }

    /**
     * @return object{role: string, branch_id: string|null, is_active: int|bool}
     *
     * @throws ValidationException
     */
    public static function requireMembership(Company $company, User $user): object
    {
        return self::membership($company, $user)
            ?? throw ValidationException::withMessages(['user' => "{$user->name} is not a user of {$company->name}."]);
    }

    /**
     * An owner always sees every shop; anyone else may be limited to one active shop of this business.
     *
     * @throws ValidationException
     */
    public static function ensureBranchFits(Company $company, CompanyRole $role, ?string $branchId): void
    {
        if ($branchId === null) {
            return;
        }

        if ($role === CompanyRole::Owner) {
            throw ValidationException::withMessages(['branch_id' => 'An owner always sees every shop. Choose "Every shop".']);
        }

        $exists = Branch::withoutCompanyScope()
            ->where('company_id', $company->getKey())
            ->where('is_active', true)
            ->whereKey($branchId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['branch_id' => 'Choose one of your open shops.']);
        }
    }

    /**
     * @throws ValidationException
     */
    public static function ensureNotSelf(User $actor, User $member, string $message): void
    {
        if ($actor->is($member)) {
            throw ValidationException::withMessages(['user' => $message]);
        }
    }

    /**
     * Shop name for a membership or invitation (null = every shop; a closed shop keeps its name).
     */
    public static function branchName(Company $company, ?string $branchId): ?string
    {
        if ($branchId === null) {
            return null;
        }

        $name = Branch::withoutCompanyScope()->withTrashed()->where('company_id', $company->getKey())->whereKey($branchId)->value('name');

        return is_string($name) ? $name : null;
    }
}
