<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "At least one active owner must remain" rule, shared by the membership actions. Call inside a transaction.
 */
final class CompanyOwners
{
    /**
     * @throws ValidationException when `$user` is the company's last active owner
     */
    public static function ensureAnotherOwner(Company $company, User $user, string $message): void
    {
        $others = DB::table('company_user')
            ->where('company_id', $company->getKey())
            ->where('role', CompanyRole::Owner->value)
            ->where('is_active', true)
            ->where('user_id', '!=', $user->getKey())
            ->lockForUpdate()
            ->exists();

        if (! $others) {
            throw ValidationException::withMessages(['user' => $message]);
        }
    }

    /**
     * @return object{role: string, is_active: int|bool}|null
     */
    public static function membership(Company $company, User $user): ?object
    {
        /** @var object{role: string, is_active: int|bool}|null $row */
        $row = DB::table('company_user')
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->lockForUpdate()
            ->first(['role', 'is_active']);

        return $row;
    }
}
