<?php

namespace App\Domain\Shared\Support;

use Illuminate\Support\Str;

/**
 * ULID helpers. A ULID is 26 characters of Crockford base32 (0-9, A-Z without I, L, O, U), upper case,
 * first character 0-7. Till ids are stored exactly as received, so validation is strict upper case.
 */
final class Ulid
{
    public const PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/';

    public static function new(): string
    {
        return strtoupper((string) Str::ulid());
    }

    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::PATTERN, $value) === 1;
    }
}
