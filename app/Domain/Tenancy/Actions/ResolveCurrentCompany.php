<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Picks the company a user is working in: the preferred (session) company if the user is an active member
 * of it, otherwise their first active company by name. Returns null when the user has no active membership.
 * Cancelled companies are never returned (their users are signed out). Suspended ones are: the `company`
 * middleware shows them the "on hold" page.
 * The returned company carries its pivot as `$company->membership` (role, is_active).
 */
class ResolveCurrentCompany
{
    public function handle(User $user, ?string $preferredCompanyId = null): ?Company
    {
        $companies = $this->activeCompanies($user);

        if ($preferredCompanyId !== null) {
            $preferred = $companies->firstWhere('id', $preferredCompanyId);

            if ($preferred !== null) {
                return $preferred;
            }
        }

        return $companies->first();
    }

    /**
     * Companies the user can currently work in, ordered by name.
     *
     * @return Collection<int, Company>
     */
    public function activeCompanies(User $user): Collection
    {
        return $user->companies()
            ->wherePivot('is_active', true)
            ->where('companies.status', '!=', CompanyStatus::Cancelled->value)
            ->orderBy('companies.name')
            ->get();
    }

    /**
     * Whether the user has an active membership of a cancelled company (to explain why they were signed out).
     */
    public function hasCancelledMembership(User $user): bool
    {
        return $user->companies()
            ->wherePivot('is_active', true)
            ->where('companies.status', CompanyStatus::Cancelled->value)
            ->exists();
    }
}
