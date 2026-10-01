<?php

namespace App\Domain\Security\Actions;

use App\Domain\Security\Contracts\TwoFactorUser;
use App\Domain\Security\Enums\TwoFactorArea;
use App\Domain\Security\Support\TwoFactorSession;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * A portal user turns their own two-factor sign-in off (their password is checked by the form request). Not allowed
 * while their business requires it; admins can never turn it off (only an owner can reset it, ResetTwoFactor).
 * Audited as `two_factor.disabled`.
 */
final class DisableTwoFactor
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Session $session, TwoFactorUser&Model $user, ?Company $company): void
    {
        if (! $user->hasTwoFactorEnabled()) {
            return;
        }

        if ($company !== null && $company->require_two_factor) {
            throw ValidationException::withMessages([
                'password' => "{$company->name} requires two-factor sign-in, so it cannot be turned off.",
            ]);
        }

        $user->clearTwoFactor();
        TwoFactorSession::forget($session, TwoFactorArea::Web);

        $this->audit->handle('two_factor.disabled', $user, ['twoFactor' => true], ['twoFactor' => false], ['guard' => TwoFactorArea::Web->value], actor: $user, companyId: $company?->id);
    }
}
