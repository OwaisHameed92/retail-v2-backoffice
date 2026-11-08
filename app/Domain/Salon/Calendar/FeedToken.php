<?php

namespace App\Domain\Salon\Calendar;

use SensitiveParameter;

/**
 * A staff calendar feed token: 32 random bytes as base64url (43 characters, no padding), part of the feed address
 * `/salon/ical/{branchId}/{userId}/{token}.ics`. Stored as its SHA-256 hash and last 4 characters only; shown once.
 * Never logged.
 */
final class FeedToken
{
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    public static function last4(#[SensitiveParameter] string $token): string
    {
        return substr($token, -4);
    }

    /** The shape a token can have (anything else is a 404 without a database read). */
    public static function wellFormed(#[SensitiveParameter] string $token): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{32,64}\z/', $token) === 1;
    }
}
