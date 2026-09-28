<?php

namespace App\Domain\Licensing\Signing;

use InvalidArgumentException;

/**
 * Signing key id (contract v1.3.1 §17.2, till `LicenceTokens.KidFor`): "k" + the first 8 lower-case hex digits
 * of SHA-256(raw 32-byte Ed25519 public key), e.g. k13799fa4.
 */
final class Kid
{
    public const PATTERN = '/^k[0-9a-f]{8}$/';

    public static function for(string $publicKey): string
    {
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new InvalidArgumentException('Ed25519 public key must be 32 bytes.');
        }

        return 'k'.substr(hash('sha256', $publicKey), 0, 8);
    }
}
