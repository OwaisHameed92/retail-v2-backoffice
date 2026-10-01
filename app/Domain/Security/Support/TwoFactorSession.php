<?php

namespace App\Domain\Security\Support;

use App\Domain\Security\Contracts\TwoFactorUser;
use App\Domain\Security\Enums\TwoFactorArea;
use Illuminate\Contracts\Session\Session;
use SensitiveParameter;

/**
 * Per-session two-factor state, one entry per guard:
 * - "passed": this session proved the second factor for this account and this secret (a reset or a new secret
 *   makes it stale, so the person is asked again);
 * - "pending": the encrypted secret being set up, until the first code confirms it.
 */
final class TwoFactorSession
{
    public const PASSED_KEY = 'two_factor_passed';

    public const PENDING_KEY = 'two_factor_setup';

    public static function passed(Session $session, TwoFactorArea $area, TwoFactorUser $user): bool
    {
        $entry = $session->get(self::PASSED_KEY.'.'.$area->value);

        return is_array($entry)
            && ($entry['id'] ?? null) === (string) $user->getKey()
            && hash_equals((string) ($entry['stamp'] ?? ''), $user->twoFactorStamp());
    }

    public static function markPassed(Session $session, TwoFactorArea $area, TwoFactorUser $user): void
    {
        $session->put(self::PASSED_KEY.'.'.$area->value, ['id' => (string) $user->getKey(), 'stamp' => $user->twoFactorStamp()]);
    }

    public static function forget(Session $session, TwoFactorArea $area): void
    {
        $session->forget([self::PASSED_KEY.'.'.$area->value, self::PENDING_KEY.'.'.$area->value]);
    }

    /** The secret being set up for this account, if any. */
    public static function pendingSecret(Session $session, TwoFactorArea $area, TwoFactorUser $user): ?string
    {
        $entry = $session->get(self::PENDING_KEY.'.'.$area->value);

        if (! is_array($entry) || ($entry['id'] ?? null) !== (string) $user->getKey() || ! is_string($entry['secret'] ?? null)) {
            return null;
        }

        try {
            $secret = decrypt($entry['secret']);
        } catch (\Throwable) {
            return null;
        }

        return is_string($secret) ? $secret : null;
    }

    public static function putPendingSecret(Session $session, TwoFactorArea $area, TwoFactorUser $user, #[SensitiveParameter] string $secret): void
    {
        $session->put(self::PENDING_KEY.'.'.$area->value, ['id' => (string) $user->getKey(), 'secret' => encrypt($secret)]);
    }

    public static function forgetPending(Session $session, TwoFactorArea $area): void
    {
        $session->forget(self::PENDING_KEY.'.'.$area->value);
    }
}
