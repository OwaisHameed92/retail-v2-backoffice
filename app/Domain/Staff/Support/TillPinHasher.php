<?php

namespace App\Domain\Staff\Support;

/**
 * Makes and checks the till's `User.pinHash` in the till's own text format (contract §10.7, ANSWERS-2026-10-01 §1,
 * the till's `Pbkdf2PinHasher.cs`): `pbkdf2$<iterations>$<base64 salt>$<base64 subkey>` — PBKDF2-HMAC-SHA256 over
 * the PIN's UTF-8 bytes, no pepper, 100,000 iterations, a 16-byte random salt, a 32-byte subkey, standard Base64
 * with `=` padding, at most 200 characters. Reading takes the iteration count and subkey length from the string.
 *
 * Not ASP.NET Identity v3: the till refuses those `AQAAAA…` blobs (the PIN would never work). Hashes the portal
 * wrote in that format before 2026-10-01 are "PIN needs resetting" (isTillFormat false): never sent in a pull.
 *
 * The PIN itself is never stored, logged or returned: callers pass it straight in and keep only the hash.
 */
final class TillPinHasher
{
    public const ITERATIONS = 100_000;

    public const MAX_LENGTH = 200;

    private const SALT_BYTES = 16;

    private const KEY_BYTES = 32;

    public function hash(string $pin): string
    {
        return self::hashWithSalt($pin, random_bytes(self::SALT_BYTES));
    }

    /** The format with a given salt (tests pin the till's samples with salt bytes 00..0F). */
    public static function hashWithSalt(string $pin, string $salt, int $iterations = self::ITERATIONS): string
    {
        $key = hash_pbkdf2('sha256', $pin, $salt, $iterations, self::KEY_BYTES, true);

        return 'pbkdf2$'.$iterations.'$'.base64_encode($salt).'$'.base64_encode($key);
    }

    /** True when `$hash` is the till's format of `$pin`. Any other format (or a blank hash) is simply not a match. */
    public function verify(string $pin, ?string $hash): bool
    {
        $parts = self::parse($hash);

        if ($parts === null) {
            return false;
        }

        [$iterations, $salt, $key] = $parts;

        return hash_equals($key, hash_pbkdf2('sha256', $pin, $salt, $iterations, strlen($key), true));
    }

    /** The till reads this hash: four `$` parts, `pbkdf2` exactly, decimal count, standard Base64, ≤ 200 chars. */
    public static function isTillFormat(?string $hash): bool
    {
        return self::parse($hash) !== null;
    }

    /** A stored hash the till cannot read (an Identity v3 one from an older portal): the PIN must be set again. */
    public static function needsReset(?string $hash): bool
    {
        return $hash !== null && $hash !== '' && ! self::isTillFormat($hash);
    }

    /**
     * @return array{0: int, 1: string, 2: string}|null iterations, salt, subkey
     */
    private static function parse(?string $hash): ?array
    {
        if ($hash === null || strlen($hash) > self::MAX_LENGTH
            || preg_match('/^pbkdf2\$([1-9]\d{0,8})\$([A-Za-z0-9+\/]+={0,2})\$([A-Za-z0-9+\/]+={0,2})$/', $hash, $m) !== 1) {
            return null;
        }

        $salt = base64_decode($m[2], true);
        $key = base64_decode($m[3], true);

        if ($salt === false || $key === false || strlen($salt) < 8 || strlen($key) < 16) {
            return null;
        }

        return [(int) $m[1], $salt, $key];
    }
}
