<?php

namespace App\Domain\Partners\Support;

use SensitiveParameter;

/**
 * Partner API key format and hashing. A key is `spk_` + 40 random lower-case base32 characters (200 bits), e.g.
 * `spk_7k2q9dmf3xra…`. It travels only as `Authorization: Bearer <key>`.
 *
 * Stored as an HMAC-SHA256 (APP_KEY; APP_PREVIOUS_KEYS still match) + the last 4 characters. The plain key is never
 * stored, logged or put in a URL. Webhook signing secrets use the same generator with the `whsec_` prefix.
 */
final class PartnerKeySecret
{
    public const PREFIX = 'spk_';

    public const SECRET_PREFIX = 'whsec_';

    private const ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

    private const LENGTH = 40;

    public static function generate(string $prefix = self::PREFIX): string
    {
        $chars = '';

        foreach (str_split(random_bytes(self::LENGTH)) as $byte) {
            $chars .= self::ALPHABET[ord($byte) % 32];
        }

        return $prefix.$chars;
    }

    public static function hash(#[SensitiveParameter] string $key): string
    {
        return hash_hmac('sha256', trim($key), (string) config('app.key'));
    }

    /**
     * The key's hash under APP_KEY and every APP_PREVIOUS_KEYS entry, so keys survive an APP_KEY rotation. Look up with
     * `whereIn('key_hash', …)`, then compare with hash_equals.
     *
     * @return list<string>
     */
    public static function hashCandidates(#[SensitiveParameter] string $key): array
    {
        $secrets = array_filter([config('app.key'), ...(array) config('app.previous_keys', [])], fn ($secret) => is_string($secret) && $secret !== '');

        return array_values(array_unique(array_map(fn (string $secret) => hash_hmac('sha256', trim($key), $secret), $secrets)));
    }

    public static function last4(#[SensitiveParameter] string $key): string
    {
        return substr(trim($key), -4);
    }

    /** Could this be a partner key at all? (cheap check before a database lookup) */
    public static function looksValid(#[SensitiveParameter] string $key): bool
    {
        return preg_match('/^spk_[0-9a-hjkmnp-tv-z]{'.self::LENGTH.'}$/', trim($key)) === 1;
    }

    public static function mask(string $last4): string
    {
        return self::PREFIX.'••••'.$last4;
    }
}
