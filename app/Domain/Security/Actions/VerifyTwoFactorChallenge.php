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
 * The second step of signing in: a 6-digit code from the app (each time step once only) or one of the recovery
 * codes (used up; audited as `two_factor.recovery_code_used`). Five tries a minute. On success this session is marked
 * as passed and the session id is renewed.
 */
final class VerifyTwoFactorChallenge
{
    public function __construct(
        private readonly Totp $totp,
        private readonly TwoFactorThrottle $throttle,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Session $session, TwoFactorArea $area, TwoFactorUser&Model $user, #[SensitiveParameter] string $input, ?string $companyId = null): void
    {
        if (! $user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['code' => 'Two-factor sign-in is not on for this account.']);
        }

        $this->throttle->ensureNotLocked($area, $user);

        $input = trim($input);
        $usedRecoveryCode = false;

        if (RecoveryCodes::looksLikeCode($input)) {
            $usedRecoveryCode = RecoveryCodes::consume($user, $input);
            $ok = $usedRecoveryCode;
        } else {
            $step = $this->totp->verify((string) $user->twoFactorSecret(), $input, $user->twoFactorLastStep());
            $ok = $step !== null;

            if ($step !== null) {
                $user->forceFill(['two_factor_last_step' => $step])->save();
            }
        }

        if (! $ok) {
            $this->throttle->hit($area, $user, $companyId);

            throw ValidationException::withMessages(['code' => 'That code did not work. Enter the newest 6-digit code from your app, or a recovery code.']);
        }

        $this->throttle->clear($area, $user);

        if ($usedRecoveryCode) {
            $this->audit->handle('two_factor.recovery_code_used', $user, null, null, [
                'guard' => $area->value,
                'remaining' => $user->remainingRecoveryCodes(),
            ], actor: $user, companyId: $companyId);
        }

        $session->migrate(true);
        TwoFactorSession::markPassed($session, $area, $user);
    }
}
