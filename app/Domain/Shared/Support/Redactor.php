<?php

namespace App\Domain\Shared\Support;

/**
 * Replaces secret values (passwords, licence keys, API keys, tokens, hashes) before anything is stored or logged.
 *
 * Keys are compared case-insensitively with "_" and "-" removed, so `licence_key`, `licenceKey` and
 * `Licence-Key` all match.
 */
final class Redactor
{
    public const REDACTED = '[redacted]';

    /** Exact key names (normalised) that always hold a secret. */
    private const EXACT = [
        'password',
        'passwordconfirmation',
        'currentpassword',
        'licencekey',
        'licensekey',
        'apikey',
        'syncapikey',
        'token',
        'secret',
        'privatekey',
        'authorization',
    ];

    /** Key endings (normalised) that hold a secret, e.g. `key_hash`, `remember_token`, `client_secret`. */
    private const SUFFIXES = ['hash', 'token', 'secret', 'password', 'apikey', 'privatekey'];

    /**
     * @param  array<array-key, mixed>|null  $data
     * @return array<array-key, mixed>|null
     */
    public static function redact(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSecretKey($key)) {
                $data[$key] = $value === null ? null : self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }

    public static function isSecretKey(string $key): bool
    {
        $normalised = strtolower(str_replace(['_', '-', ' '], '', $key));

        if (in_array($normalised, self::EXACT, true)) {
            return true;
        }

        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($normalised, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
