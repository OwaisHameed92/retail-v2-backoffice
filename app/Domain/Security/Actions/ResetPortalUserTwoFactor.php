<?php

namespace App\Domain\Security\Actions;

use App\Domain\Admin\Models\Admin;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Switch & Save support resets a portal user's two-factor sign-in (lost phone and recovery codes), from the tenant
 * page. They sign in with their password and, if their business requires it, set it up again. Audited as
 * `user.two_factor_reset` for the business.
 */
final class ResetPortalUserTwoFactor
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $user, Company $company, Admin $actor): void
    {
        if (! $user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['user' => "{$user->name} does not use two-factor sign-in."]);
        }

        $user->clearTwoFactor();

        $this->audit->handle('user.two_factor_reset', $user, ['twoFactor' => true], ['twoFactor' => false], actor: $actor, companyId: $company->id);
    }
}
