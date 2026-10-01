<?php

namespace App\Domain\Security\Support;

use App\Domain\Security\Contracts\TwoFactorUser;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;

/**
 * Ten one-time recovery codes ("k7m2p-x9qrt", 50 bits each). Only keyed SHA-256 hashes are stored; the plain codes
 * exist in the one reply that shows them. Using a code removes it.
 */
final class RecoveryCodes
{
    public const COUNT = 10;

    /** No 0/o, 1/l/i: easy to read back from paper. */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /**
     * @return list<string>
     */
    public static function generate(): array
    {
        $codes = [];

        while (count($codes) < self::COUNT) {
            $code = self::chunk().'-'.self::chunk();
            $codes[$code] = $code;
        }

        return array_values($codes);
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    public static function hashAll(#[SensitiveParameter] array $codes): array
    {
        return array_map(fn (string $code) => self::hash($code), $codes);
    }

    public static function hash(#[SensitiveParameter] string $code): string
    {
        $key = (string) config('app.key');

        return hash_hmac('sha256', self::normalise($code), 'recovery-code|'.$key);
    }

    /** Looks like a recovery code (so the challenge can tell it from a 6-digit code). */
    public static function looksLikeCode(string $input): bool
    {
        return preg_match('/^[a-z0-9]{5}-?[a-z0-9]{5}$/', self::normalise($input, keepDash: true)) === 1;
    }

    /**
     * Removes the matching code from the account. True when one matched.
     */
    public static function consume(TwoFactorUser&Model $user, #[SensitiveParameter] string $code): bool
    {
        $hash = self::hash($code);
        $stored = $user->two_factor_recovery_codes ?? [];

        foreach ($stored as $index => $candidate) {
            if (hash_equals($candidate, $hash)) {
                unset($stored[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($stored)])->save();

                return true;
            }
        }

        return false;
    }

    private static function normalise(string $code, bool $keepDash = false): string
    {
        $code = strtolower((string) preg_replace('/\s+/', '', $code));

        return $keepDash ? $code : str_replace('-', '', $code);
    }

    private static function chunk(): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < 5; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }
}
