<?php

namespace Tests\Feature\Security;

use App\Domain\Security\Contracts\TwoFactorUser;
use App\Domain\Security\Support\RecoveryCodes;
use App\Domain\Security\Support\Totp;
use Illuminate\Database\Eloquent\Model;

/** Two-factor test helpers: turn it on for an account and read the current code. */
final class TwoFactorHelpers
{
    /**
     * @return array{secret: string, codes: list<string>}
     */
    public static function enable(TwoFactorUser&Model $user): array
    {
        $secret = (new Totp)->newSecret();
        $codes = RecoveryCodes::generate();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => RecoveryCodes::hashAll($codes),
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => null,
        ])->save();

        return ['secret' => $secret, 'codes' => $codes];
    }

    public static function code(string $secret): string
    {
        return (new Totp)->current($secret);
    }

    /** A valid-looking code that is not the current one (nor the one either side). */
    public static function wrongCode(string $secret): string
    {
        $totp = new Totp;

        for ($n = 0; $n < 1000000; $n += 7919) {
            $candidate = str_pad((string) $n, 6, '0', STR_PAD_LEFT);
            if ($totp->verify($secret, $candidate) === null) {
                return $candidate;
            }
        }

        return '000000';
    }
}
