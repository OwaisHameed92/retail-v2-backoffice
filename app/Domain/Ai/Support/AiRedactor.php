<?php

namespace App\Domain\Ai\Support;

use App\Domain\Shared\Support\Redactor;

/**
 * Cleans data before it is sent to the model provider:
 * - secrets by key name (the shared Redactor: passwords, licence keys, API keys, tokens, hashes);
 * - personal data we do not need, by key name (`config('ai.redact_keys')`: email, phone, address...);
 * - secret-looking strings anywhere in text (licence keys, Anthropic keys, bearer tokens) and e-mail addresses.
 */
final class AiRedactor
{
    public const PERSONAL = '[removed]';

    /** Patterns replaced inside any string value. */
    private const PATTERNS = [
        // Licence keys: SSP-XXXX-XXXX-XXXX-XXXX (Crockford base32), with or without dashes.
        '/\bSSP-?[0-9A-Z]{4}-?[0-9A-Z]{4}-?[0-9A-Z]{4}-?[0-9A-Z]{4}\b/i' => '[licence key]',
        // Provider / API keys and bearer tokens.
        '/\bsk-[A-Za-z0-9_\-]{16,}\b/' => '[secret]',
        '/\bBearer\s+[A-Za-z0-9._\-]{16,}/i' => 'Bearer [secret]',
        // E-mail addresses.
        '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i' => '[email]',
    ];

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function data(array $data): array
    {
        $data = Redactor::redact($data) ?? [];

        return self::walk($data, self::personalKeys());
    }

    /**
     * Free text from a person (a question) or a model: only secret-looking strings are replaced.
     */
    public static function text(string $text): string
    {
        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $personalKeys
     * @return array<array-key, mixed>
     */
    private static function walk(array $data, array $personalKeys): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(self::normalise($key), $personalKeys, true)) {
                $data[$key] = $value === null ? null : self::PERSONAL;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::walk($value, $personalKeys);
            } elseif (is_string($value)) {
                $data[$key] = self::text($value);
            }
        }

        return $data;
    }

    /**
     * @return list<string>
     */
    private static function personalKeys(): array
    {
        return array_values(array_map(
            fn (mixed $key) => self::normalise((string) $key),
            (array) config('ai.redact_keys', []),
        ));
    }

    private static function normalise(string $key): string
    {
        return strtolower(str_replace(['_', '-', ' '], '', $key));
    }
}
