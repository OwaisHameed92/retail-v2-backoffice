<?php

namespace App\Domain\Staff\Support;

/**
 * Makes and checks the till's `User.pinHash` (contract §10.7: "PBKDF2, never the PIN"). The contract does not give
 * the layout; the till is .NET, so we use the standard ASP.NET Core Identity v3 one (to be confirmed by the EPOS
 * team — the only place to change if theirs differs): base64 of
 * `0x01 | prf (uint32 BE) | iterations (uint32 BE) | salt length (uint32 BE) | salt | derived key`, prf 1 =
 * HMAC-SHA256, 10,000 iterations (Identity v3's own count), 16-byte salt, 32-byte key. The count travels inside the
 * hash, so it can change later without breaking older hashes. It is kept modest on purpose: a new PIN is checked
 * against every colleague's hash (StaffGuards), and a 4-digit PIN's strength is the till's lock-out, not the count.
 *
 * The PIN itself is never stored, logged or returned: callers pass it straight in and keep only the hash.
 */
final class TillPinHasher
{
    public const ITERATIONS = 10_000;

    private const MARKER = 0x01;

    private const PRF_SHA256 = 1;

    private const SALT_BYTES = 16;

    private const KEY_BYTES = 32;

    /** @var array<int, string> */
    private const PRFS = [0 => 'sha1', 1 => 'sha256', 2 => 'sha512'];

    public function hash(string $pin): string
    {
        $salt = random_bytes(self::SALT_BYTES);
        $key = hash_pbkdf2('sha256', $pin, $salt, self::ITERATIONS, self::KEY_BYTES, true);

        return base64_encode(chr(self::MARKER).pack('NNN', self::PRF_SHA256, self::ITERATIONS, self::SALT_BYTES).$salt.$key);
    }

    /** True when `$hash` is a v3 hash of `$pin`. Any other format (or a blank hash) is simply not a match. */
    public function verify(string $pin, ?string $hash): bool
    {
        $raw = $hash === null || $hash === '' ? false : base64_decode($hash, true);

        if ($raw === false || strlen($raw) < 13 + self::SALT_BYTES || ord($raw[0]) !== self::MARKER) {
            return false;
        }

        /** @var array{prf: int, iterations: int, saltLength: int} $head */
        $head = unpack('Nprf/Niterations/NsaltLength', substr($raw, 1, 12));
        $algo = self::PRFS[$head['prf']] ?? null;
        $key = substr($raw, 13 + $head['saltLength']);

        if ($algo === null || $head['iterations'] < 1 || $head['saltLength'] < 8 || strlen($key) < 16) {
            return false;
        }

        $salt = substr($raw, 13, $head['saltLength']);

        return hash_equals($key, hash_pbkdf2($algo, $pin, $salt, $head['iterations'], strlen($key), true));
    }
}
