<?php

namespace App\Domain\Operations\Support;

use App\Domain\Shared\Support\Redactor;

/**
 * Removes anything that looks like a secret from free text (exception messages and traces, task errors) before it is
 * stored or sent to the browser. Key names use the shared {@see Redactor} rules (password, token, secret, *_hash,
 * api key…); values are also caught by shape: licence keys, bearer tokens, provider keys, URL passwords and long
 * random strings (hashes, base64 keys, reset tokens).
 */
final class SecretText
{
    public const REDACTED = Redactor::REDACTED;

    /** Words that make a `name=value` / `"name": "value"` pair secret even when the shared rules would not match. */
    private const NAME = '[A-Za-z_\-]*(?:password|passwd|pwd|secret|token|api[_\-]?key|private[_\-]?key|access[_\-]?key|key[_\-]?hash|authorization|signature|licence[_\-]?key|license[_\-]?key)[A-Za-z_\-]*';

    public static function clean(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $patterns = [
            // JSON members: "password":"…", "apiKey": "…".
            '/("'.self::NAME.'"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i' => '$1"'.self::REDACTED.'"',
            // name=value and name: value (query strings, log lines, exception messages).
            '/(\b'.self::NAME.'\b\s*[=:]\s*)(?!\[redacted\])(?:(?:Bearer|Basic)\s+)?[^\s&"\',;)]+/i' => '$1'.self::REDACTED,
            // URL query members named key / k / sig (TRUD and signed URLs).
            '/([?&](?:key|k|sig)=)[^&\s"\']+/i' => '$1'.self::REDACTED,
            // Passwords inside URLs: scheme://user:password@host.
            '#(\b[a-z][a-z0-9+.\-]*://[^:/\s@]+:)[^@/\s]+@#i' => '$1'.self::REDACTED.'@',
            // Bearer / Basic credentials.
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=\-]{8,}/i' => '$1 '.self::REDACTED,
            // Licence keys: SSP-XXXX-XXXX-XXXX-XXXX.
            '/\bSSP-?[0-9A-Z]{4}-?[0-9A-Z]{4}-?[0-9A-Z]{4}-?[0-9A-Z]{4}\b/i' => '[licence key]',
            // Provider keys (sk-…, sk_live_…, pk_live_…).
            '/\b(?:sk|pk|rk)[-_](?:live_|test_)?[A-Za-z0-9_\-]{16,}\b/' => self::REDACTED,
            // Hex hashes and tokens (40+ characters, e.g. sha1/sha256, password reset tokens).
            '/\b[a-f0-9]{40,}\b/i' => self::REDACTED,
        ];

        $text = (string) preg_replace(array_keys($patterns), array_values($patterns), $text);

        // PHP-serialised secrets (job payloads): s:8:"password";s:6:"hunter";
        $text = (string) preg_replace_callback(
            '/(s:\d+:"(?:\x00[^"\x00]*\x00)?([A-Za-z_][A-Za-z0-9_]*)";)s:\d+:"[^"]*";/',
            fn (array $m) => Redactor::isSecretKey($m[2]) ? $m[1].'s:'.strlen(self::REDACTED).':"'.self::REDACTED.'";' : $m[0],
            $text,
        );

        // Long random strings: 32+ characters mixing upper case, lower case and digits (base64 keys, random tokens).
        return (string) preg_replace_callback(
            '/[A-Za-z0-9+_\-]{32,}={0,2}/',
            fn (array $m) => preg_match('/[A-Z]/', $m[0]) && preg_match('/[a-z]/', $m[0]) && preg_match('/\d/', $m[0]) ? self::REDACTED : $m[0],
            $text,
        );
    }

    /** First non-empty line, cleaned and cut to `$limit` characters. */
    public static function firstLine(?string $text, int $limit = 300): string
    {
        foreach (preg_split('/\R/', (string) $text) ?: [] as $line) {
            if (trim($line) !== '') {
                return mb_strimwidth(self::clean(trim($line)), 0, $limit, '…');
            }
        }

        return '';
    }
}
