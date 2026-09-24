<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;

/**
 * Switches the user's working company. Only companies with an active membership are allowed.
 */
class SwitchCurrentCompany
{
    public const SESSION_KEY = 'current_company_id';

    public function __construct(private readonly ResolveCurrentCompany $resolver) {}

    /**
     * @throws AuthorizationException
     */
    public function handle(User $user, string $companyId, Session $session): Company
    {
        $company = $this->resolver->activeCompanies($user)->firstWhere('id', $companyId);

        if ($company === null) {
            throw new AuthorizationException('You are not a member of that business.');
        }

        $session->put(self::SESSION_KEY, $company->id);
        // Branches belong to one company: start the new one on "All branches".
        $session->forget(SwitchCurrentBranch::SESSION_KEY);

        return $company;
    }
}
