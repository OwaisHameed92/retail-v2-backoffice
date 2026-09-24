<?php

namespace App\Domain\Licensing\Signing;

use App\Domain\Licensing\Signing\Exceptions\NoActiveSigningKey;
use App\Domain\Shared\Support\Ulid;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;

/**
 * Signs licence tokens with the active key: compact JWS, header {"alg":"EdDSA","kid":…,"typ":"sspos-licence+jwt"}.
 *
 * Adds `iss` (config licence.issuer), `iat` (now, Unix seconds) and `jti` (ULID) when the caller has not set
 * them. Business claims (lic, status, validUntil, …) are the caller's job (module 1.5).
 */
class LicenceTokenSigner
{
    public function __construct(
        private readonly KeyStore $keys,
        private readonly Config $config,
    ) {}

    /**
     * @param  array<string, mixed>  $claims
     *
     * @throws NoActiveSigningKey
     * @throws InvalidArgumentException when the claims carry a foreign `iss`.
     */
    public function sign(array $claims): string
    {
        $issuer = (string) $this->config->get('licence.issuer');

        if (array_key_exists('iss', $claims) && $claims['iss'] !== $issuer) {
            throw new InvalidArgumentException('Licence token iss must be the configured issuer.');
        }

        $claims = [
            'iss' => $issuer,
            'iat' => CarbonImmutable::now()->getTimestamp(),
            'jti' => Ulid::new(),
            ...$claims,
        ];

        $key = $this->keys->active();

        $header = [
            'alg' => Ed25519Jws::ALG,
            'kid' => $key->kid,
            'typ' => (string) $this->config->get('licence.token_type'),
        ];

        return Ed25519Jws::sign($header, json_encode($claims, Ed25519Jws::JSON_FLAGS), $key->secretKey());
    }
}
