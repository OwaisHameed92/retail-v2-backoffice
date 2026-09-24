<?php

namespace App\Domain\Licensing\Signing;

use App\Domain\Licensing\Signing\Exceptions\MalformedToken;

/**
 * Base64url without padding (RFC 7515 section 2). Decoding is strict: only the url-safe alphabet, no padding,
 * no whitespace, and the input must be the canonical encoding of its bytes (no stray trailing bits), so every
 * token has exactly one valid spelling.
 */
final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @throws MalformedToken
     */
    public static function decode(string $encoded): string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/', $encoded) !== 1 || strlen($encoded) % 4 === 1) {
            throw new MalformedToken('Invalid base64url segment.');
        }

        $bytes = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($bytes === false || self::encode($bytes) !== $encoded) {
            throw new MalformedToken('Invalid base64url segment.');
        }

        return $bytes;
    }
}
