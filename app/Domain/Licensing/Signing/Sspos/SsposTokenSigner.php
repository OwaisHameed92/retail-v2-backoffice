<?php

namespace App\Domain\Licensing\Signing\Sspos;

use App\Domain\Licensing\Signing\Exceptions\NoActiveSigningKey;
use App\Domain\Licensing\Signing\Exceptions\UncertifiedSigningKey;
use App\Domain\Licensing\Signing\KeyStore;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Log;

/**
 * Signs portal licence tokens with the active key (contract v1.4.1 §17.2):
 * `SSPOS1.<base64url(payload JSON)>.<base64url(Ed25519 over "SSPOS1." + payload part)>`.
 *
 * Every token carries the active key's `signerCert` (§17.17). Without one, signing is refused unless
 * config('licence.allow_uncertified') is on (local/testing), and then a warning is logged: tills refuse
 * such a token unless they have the key built in.
 */
class SsposTokenSigner
{
    public function __construct(
        private readonly KeyStore $keys,
        private readonly Config $config,
    ) {}

    /**
     * @throws NoActiveSigningKey
     * @throws UncertifiedSigningKey
     */
    public function sign(LicenceClaims $claims): string
    {
        $key = $this->keys->active();

        if ($key->signerCert === null) {
            if (! (bool) $this->config->get('licence.allow_uncertified', false)) {
                throw new UncertifiedSigningKey($key->kid);
            }

            Log::warning('Licence token signed without a signer certificate; tills will refuse it.', ['kid' => $key->kid]);
        }

        return SsposCodec::sign(SsposCodec::TOKEN_PREFIX, $claims->toPayload($key->kid, $key->signerCert), $key->secretKey());
    }
}
