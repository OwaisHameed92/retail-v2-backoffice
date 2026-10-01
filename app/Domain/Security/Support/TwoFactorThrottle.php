<?php

namespace App\Domain\Security\Support;

use App\Domain\Security\Contracts\TwoFactorUser;
use App\Domain\Security\Enums\TwoFactorArea;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Five code attempts a minute per account (set-up and sign-in alike). The attempt that hits the limit is logged and
 * audited as `two_factor.locked_out`.
 */
final class TwoFactorThrottle
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 60;

    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function ensureNotLocked(TwoFactorArea $area, TwoFactorUser $user, string $field = 'code'): void
    {
        $key = self::key($area, $user);

        if (! RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return;
        }

        throw ValidationException::withMessages([$field => self::lockedMessage(RateLimiter::availableIn($key))]);
    }

    /**
     * Counts a wrong code. Locks the account's code entry when the limit is reached.
     */
    public function hit(TwoFactorArea $area, TwoFactorUser&Model $user, ?string $companyId = null): void
    {
        $key = self::key($area, $user);
        $attempts = RateLimiter::hit($key, self::DECAY_SECONDS);

        if ($attempts === self::MAX_ATTEMPTS) {
            Log::warning('Two-factor code entry locked after too many wrong codes.', [
                'guard' => $area->value,
                'account_id' => (string) $user->getKey(),
                'ip' => request()->ip(),
            ]);

            $this->audit->handle('two_factor.locked_out', $user, null, null, ['guard' => $area->value, 'attempts' => $attempts], actor: $user, companyId: $companyId);
        }
    }

    public function clear(TwoFactorArea $area, TwoFactorUser $user): void
    {
        RateLimiter::clear(self::key($area, $user));
    }

    public static function key(TwoFactorArea $area, TwoFactorUser $user): string
    {
        return 'two-factor|'.$area->value.'|'.$user->getKey();
    }

    public static function lockedMessage(int $seconds): string
    {
        return "Too many wrong codes. Wait {$seconds} seconds and try again.";
    }
}
