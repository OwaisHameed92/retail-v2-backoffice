<?php

namespace App\Domain\Security\Actions;

use App\Domain\Security\Contracts\TwoFactorUser;
use App\Domain\Security\Enums\TwoFactorArea;
use App\Domain\Security\Support\RecoveryCodes;
use App\Domain\Security\Support\Totp;
use App\Domain\Security\Support\TwoFactorSession;
use App\Domain\Security\Support\TwoFactorThrottle;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/**
 * Turns two-factor on once the first code from the app matches the pending secret. Stores the secret (encrypted)
 * and hashed recovery codes, marks this session as passed and returns the ten plain recovery codes: the only time
 * they exist. Audited as `two_factor.enabled`.
 */
final class ConfirmTwoFactorSetup
{
    public function __construct(
        private readonly Totp $totp,
        private readonly TwoFactorThrottle $throttle,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @return list<string> The recovery codes, shown once.
     *
     * @throws ValidationException
     */
    public function handle(Session $session, TwoFactorArea $area, TwoFactorUser&Model $user, #[SensitiveParameter] string $code, ?string $companyId = null): array
    {
        if ($user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['code' => 'Two-factor sign-in is already on for this account.']);
        }

        $this->throttle->ensureNotLocked($area, $user);

        $secret = TwoFactorSession::pendingSecret($session, $area, $user);

        if ($secret === null) {
            throw ValidationException::withMessages(['code' => 'The set-up has expired. Reload the page and scan the new QR code.']);
        }

        $step = $this->totp->verify($secret, $code);

        if ($step === null) {
            $this->throttle->hit($area, $user, $companyId);

            throw ValidationException::withMessages(['code' => 'That code did not match. Check the time on your phone is right and try the newest code.']);
        }

        $this->throttle->clear($area, $user);
        $codes = RecoveryCodes::generate();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => RecoveryCodes::hashAll($codes),
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $step,
        ])->save();

        TwoFactorSession::forgetPending($session, $area);
        TwoFactorSession::markPassed($session, $area, $user);

        $this->audit->handle('two_factor.enabled', $user, ['twoFactor' => false], ['twoFactor' => true], ['guard' => $area->value], actor: $user, companyId: $companyId);

        return $codes;
    }
}
