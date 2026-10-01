<?php

namespace App\Domain\Sync\Support;

use SensitiveParameter;

/**
 * Sync key format and hashing (module 2.1). A key is `SSK-` + 8 groups of 4 Crockford base32 characters
 * = 160 random bits, e.g. `SSK-7K2Q-9DMF-3XRA-P8T5-…`. It is typed on the till ("Connect") or arrives in a
 * licence reply, then travels as `Authorization: Bearer <key>`.
 *
 * Stored as an HMAC-SHA256 (APP_KEY; APP_PREVIOUS_KEYS still match) of the canonical form (upper case, no spaces or dashes) + the last 4
 * characters. The plain key is never stored, logged or put in a URL.
 */
final class SyncKeySecret
{
    public const PREFIX = 'SSK';

    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const GROUPS = 8;

    public static function generate(): string
    {
        $bytes = random_bytes(20);
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $chars = '';

        foreach (str_split($bits, 5) as $chunk) {
            $chars .= self::ALPHABET[bindec($chunk)];
        }

        return self::PREFIX.'-'.implode('-', str_split($chars, 4));
    }

    /** Upper case, spaces and dashes removed: what is hashed. */
    public static function canonical(#[SensitiveParameter] string $key): string
    {
        return strtoupper((string) preg_replace('/[\s-]+/', '', $key));
    }

    public static function hash(#[SensitiveParameter] string $key): string
    {
        return hash_hmac('sha256', self::canonical($key), (string) config('app.key'));
    }

    /**
     * The key's hash under APP_KEY and every APP_PREVIOUS_KEYS entry (security review L10), so stored keys survive an
     * APP_KEY rotation. Look up with `whereIn('key_hash', SyncKeySecret::hashCandidates($key))`; the first is hash().
     *
     * @return list<string>
     */
    public static function hashCandidates(#[SensitiveParameter] string $key): array
    {
        $secrets = array_filter([config('app.key'), ...(array) config('app.previous_keys', [])], fn ($secret) => is_string($secret) && $secret !== '');

        return array_values(array_unique(array_map(fn (string $secret) => hash_hmac('sha256', self::canonical($key), $secret), $secrets)));
    }

    public static function last4(#[SensitiveParameter] string $key): string
    {
        return substr(self::canonical($key), -4);
    }

    /** Could this be a sync key at all? (cheap check before a database lookup) */
    public static function looksValid(#[SensitiveParameter] string $key): bool
    {
        $canonical = self::canonical($key);

        return strlen($canonical) === strlen(self::PREFIX) + 4 * self::GROUPS
            && str_starts_with($canonical, self::PREFIX)
            && preg_match('/^[0-9A-HJKMNP-TV-Z]+$/', substr($canonical, strlen(self::PREFIX))) === 1;
    }

    public static function mask(string $last4): string
    {
        return self::PREFIX.'-••••-…-'.$last4;
    }
}
