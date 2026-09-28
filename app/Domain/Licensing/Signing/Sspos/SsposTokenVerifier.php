<?php

namespace App\Domain\Licensing\Signing\Sspos;

use App\Domain\Licensing\Signing\Base64Url;
use App\Domain\Licensing\Signing\Exceptions\BadSignerCertificate;
use App\Domain\Licensing\Signing\Exceptions\InvalidSignature;
use App\Domain\Licensing\Signing\Exceptions\MalformedToken;
use App\Domain\Licensing\Signing\Exceptions\UnknownKey;
use App\Domain\Licensing\Signing\Exceptions\UnsupportedVersion;
use App\Domain\Licensing\Signing\KeyStore;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Throwable;

/**
 * Verifies an `SSPOS1.` licence token (contract v1.3.1 §17.2 "How a till verifies"), in the till's order:
 * format → `v` = 1 → known kid → signer certificate (when present) → Ed25519 signature over the payload
 * part exactly as received (never re-serialised).
 *
 * Known kids: our own keys (active, or retired within the keep period) and config('licence.trusted_keys')
 * (the owner's generator keys, for local tokens in redeem/migrate). A `signerCert` in the token must come
 * from a configured approver, be for the token's kid and that kid's public key, and not have ended before
 * the token's issuedAt. Dates, binding and limits are the caller's job.
 */
class SsposTokenVerifier
{
    public function __construct(
        private readonly KeyStore $keys,
        private readonly Config $config,
    ) {}

    /**
     * @throws MalformedToken
     * @throws UnsupportedVersion
     * @throws UnknownKey
     * @throws BadSignerCertificate
     * @throws InvalidSignature
     */
    public function verify(string $token): VerifiedSsposToken
    {
        $parts = SsposCodec::parse(SsposCodec::TOKEN_PREFIX, $token);
        $payload = $parts['payload'];
        $v = $payload['v'] ?? null;

        if (is_int($v) && $v > 1) {
            throw new UnsupportedVersion('Licence token version is newer than 1.');
        }

        if ($v !== 1) {
            throw new MalformedToken('Licence token v must be 1.');
        }

        $kid = $payload['kid'] ?? null;

        if (! is_string($kid) || preg_match('/^[A-Za-z0-9._-]{1,64}$/', $kid) !== 1) {
            throw new MalformedToken('Missing or invalid kid.');
        }

        $publicKey = $this->publicKeyFor($kid) ?? throw new UnknownKey('Unknown or expired signing key.');
        $certificate = $this->checkCertificate($payload, $kid, $publicKey);

        if (! SsposCodec::verify($parts['signingInput'], $parts['signature'], $publicKey)) {
            throw new InvalidSignature('Signature verification failed.');
        }

        return new VerifiedSsposToken((string) preg_replace('/\s+/', '', $token), $payload, $certificate);
    }

    private function publicKeyFor(string $kid): ?string
    {
        $own = $this->keys->findForVerification($kid);

        if ($own !== null) {
            return $own->publicKey;
        }

        $trusted = $this->config->get("licence.trusted_keys.{$kid}");

        try {
            return is_string($trusted) && $trusted !== '' ? Base64Url::decode($trusted) : null;
        } catch (MalformedToken) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws BadSignerCertificate
     */
    private function checkCertificate(array $payload, string $kid, string $publicKey): ?SignerCertificate
    {
        $value = $payload['signerCert'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new BadSignerCertificate('signerCert must be a string.');
        }

        try {
            $issuedAt = is_string($payload['issuedAt'] ?? null) && $payload['issuedAt'] !== ''
                ? CarbonImmutable::parse($payload['issuedAt'])->utc()
                : throw new BadSignerCertificate('Token has no issuedAt.');
        } catch (BadSignerCertificate $e) {
            throw $e;
        } catch (Throwable) {
            throw new BadSignerCertificate('Token issuedAt is not a date.');
        }

        /** @var array<string, string> $approvers */
        $approvers = (array) $this->config->get('licence.approvers', []);

        return SignerCertificate::parse($value)->check($approvers, $issuedAt)->assertFor($kid, $publicKey);
    }
}
