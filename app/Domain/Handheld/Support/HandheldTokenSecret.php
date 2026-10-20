<?php

namespace App\Domain\Handheld\Support;

use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Handheld app token format and hashing. A token is `pda_` + 48 random letters and digits (~285 bits), e.g.
 * `pda_Q7k2…`. It travels only as `Authorization: Bearer <token>`, never in a query string.
 *
 * Stored as its SHA-256 hash + the last 4 characters. The plain token is never stored, logged or audited.
 */
final class HandheldTokenSecret
{
    public const PREFIX = 'pda_';

    private const LENGTH = 48;

    public static function generate(): string
    {
        return self::PREFIX.Str::random(self::LENGTH);
    }

    public static function hash(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', trim($token));
    }

    public static function last4(#[SensitiveParameter] string $token): string
    {
        return substr(trim($token), -4);
    }

    /** Could this be a handheld token at all? (cheap check before a database lookup) */
    public static function looksValid(#[SensitiveParameter] string $token): bool
    {
        return preg_match('/^pda_[A-Za-z0-9]{'.self::LENGTH.'}$/', trim($token)) === 1;
    }
}
