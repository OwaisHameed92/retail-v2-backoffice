<?php

namespace App\Domain\Workshop\Support;

use SensitiveParameter;

/**
 * Secrets of the BikeBook connector.
 *
 * - API key (fallback auth, header `ApiKey` only): `sswk_` + 40 random base32 characters (200 bits). Stored as an
 *   HMAC-SHA256 under APP_KEY (APP_PREVIOUS_KEYS still match) + the last 4; shown once.
 * - OAuth codes and tokens: a kind prefix (`bbac_`, `bbat_`, `bbrt_`) + 48 random characters, stored as SHA-256.
 *
 * None is ever logged, audited or accepted in a URL.
 */
final class ConnectorSecret
{
    public const KEY_PREFIX = 'sswk_';

    private const ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';

    public static function newApiKey(): string
    {
        return self::KEY_PREFIX.self::random(40);
    }

    public static function newToken(string $prefix): string
    {
        return $prefix.self::random(48);
    }

    public static function hashKey(#[SensitiveParameter] string $key): string
    {
        return hash_hmac('sha256', trim($key), (string) config('app.key'));
    }

    /**
     * @return list<string> the key's HMAC under APP_KEY and every APP_PREVIOUS_KEYS entry
     */
    public static function keyCandidates(#[SensitiveParameter] string $key): array
    {
        $secrets = array_filter([config('app.key'), ...(array) config('app.previous_keys', [])], fn ($secret) => is_string($secret) && $secret !== '');

        return array_values(array_unique(array_map(fn (string $secret) => hash_hmac('sha256', trim($key), $secret), $secrets)));
    }

    public static function hashToken(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', trim($token));
    }

    public static function last4(#[SensitiveParameter] string $key): string
    {
        return substr(trim($key), -4);
    }

    public static function looksLikeKey(#[SensitiveParameter] string $key): bool
    {
        return preg_match('/^sswk_[0-9a-hjkmnp-tv-z]{40}$/', trim($key)) === 1;
    }

    public static function looksLikeToken(#[SensitiveParameter] string $token, string $prefix): bool
    {
        return preg_match('/^'.preg_quote($prefix, '/').'[0-9a-hjkmnp-tv-z]{48}$/', trim($token)) === 1;
    }

    public static function mask(?string $last4): ?string
    {
        return $last4 === null ? null : self::KEY_PREFIX.'••••'.$last4;
    }

    private static function random(int $length): string
    {
        $chars = '';

        foreach (str_split(random_bytes($length)) as $byte) {
            $chars .= self::ALPHABET[ord($byte) % 32];
        }

        return $chars;
    }
}
