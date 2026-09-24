<?php

namespace App\Domain\Licensing\Signing;

/**
 * Public keys as a JWK Set (RFC 7517 / RFC 8037), served by module 1.5 at GET /api/v1/licence/keys.
 * Contains the active key and retired keys that still verify. Never contains secret material.
 */
class Jwks
{
    public function __construct(private readonly KeyStore $keys) {}

    /**
     * @return array{keys: list<array{kid: string, kty: string, crv: string, x: string, use: string}>}
     */
    public function current(): array
    {
        return [
            'keys' => array_map(fn (SigningKey $key) => [
                'kid' => $key->kid,
                'kty' => 'OKP',
                'crv' => 'Ed25519',
                'x' => $key->x(),
                'use' => 'sig',
            ], $this->keys->verificationKeys()),
        ];
    }
}
