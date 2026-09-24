<?php

namespace App\Domain\Licensing\Signing;

use App\Domain\Licensing\Signing\Exceptions\InvalidClaims;
use App\Domain\Licensing\Signing\Exceptions\InvalidSignature;
use App\Domain\Licensing\Signing\Exceptions\MalformedToken;
use App\Domain\Licensing\Signing\Exceptions\UnknownKey;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Verifies a licence token signed by `LicenceTokenSigner`: structure, header (alg, typ, kid, no crit),
 * signature against an active or recently retired key, then `iss`.
 *
 * It does NOT check expiry, validUntil, status or deviceId: that is the till's job (offline) and the
 * licence API's (module 1.5). Claims are only decoded after the signature has been checked.
 */
class LicenceTokenVerifier
{
    public function __construct(
        private readonly KeyStore $keys,
        private readonly Config $config,
    ) {}

    /**
     * @throws MalformedToken
     * @throws UnknownKey
     * @throws InvalidSignature
     * @throws InvalidClaims
     */
    public function verify(string $token): VerifiedToken
    {
        $jws = Ed25519Jws::parse($token);
        $header = $jws['header'];

        if (($header['alg'] ?? null) !== Ed25519Jws::ALG) {
            throw new MalformedToken('Unsupported alg: only EdDSA is accepted.');
        }

        if (($header['typ'] ?? null) !== $this->config->get('licence.token_type')) {
            throw new MalformedToken('Unexpected typ.');
        }

        // RFC 7515 4.1.11: we understand no extensions, so any "crit" means reject.
        if (array_key_exists('crit', $header)) {
            throw new MalformedToken('Unsupported crit header.');
        }

        $kid = $header['kid'] ?? null;

        if (! is_string($kid) || $kid === '' || strlen($kid) > 32) {
            throw new MalformedToken('Missing or invalid kid.');
        }

        $key = $this->keys->findForVerification($kid);

        if ($key === null) {
            throw new UnknownKey('Unknown or expired signing key.');
        }

        if (! Ed25519Jws::verify($jws['signingInput'], $jws['signature'], $key->publicKey)) {
            throw new InvalidSignature('Signature verification failed.');
        }

        $claims = Ed25519Jws::decodeJsonObject($jws['payload'], 'payload');

        if (($claims['iss'] ?? null) !== $this->config->get('licence.issuer')) {
            throw new InvalidClaims('Unexpected iss.');
        }

        return new VerifiedToken($header, $claims);
    }
}
