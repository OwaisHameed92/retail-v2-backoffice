<?php

namespace App\Domain\Licensing\Signing\Sspos;

use App\Domain\Licensing\Signing\Base64Url;
use App\Domain\Licensing\Signing\Exceptions\BadSignerCertificate;
use App\Domain\Licensing\Signing\Exceptions\MalformedToken;
use App\Domain\Licensing\Signing\Kid;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * A signer certificate (contract v1.4.1 §17.17):
 * `SSPOSCERT1.<base64url(payload)>.<base64url(approver's Ed25519 signature over "SSPOSCERT1." + payload part)>`.
 *
 * The owner's key generator (an approver) certifies our public key; we put the string, unchanged, in every
 * token as `signerCert`. It is not secret. `check()` mirrors the till's checks.
 */
final class SignerCertificate
{
    private function __construct(
        /** The exact string, whitespace removed. */
        public readonly string $value,
        public readonly string $kid,
        /** Raw 32-byte public key of the certified signer. */
        public readonly string $publicKey,
        public readonly string $name,
        public readonly CarbonImmutable $issuedAt,
        public readonly ?CarbonImmutable $expiresAt,
        public readonly string $approvedBy,
        private readonly string $signingInput,
        private readonly string $signature,
    ) {}

    /**
     * Decodes the certificate. Nothing in it is trusted until `check()` passes.
     *
     * @throws BadSignerCertificate
     */
    public static function parse(string $certificate): self
    {
        try {
            $parts = SsposCodec::parse(SsposCodec::CERT_PREFIX, $certificate);
        } catch (MalformedToken $e) {
            throw new BadSignerCertificate('Malformed signer certificate: '.$e->getMessage());
        }

        $p = $parts['payload'];

        if (($p['v'] ?? null) !== 1) {
            throw new BadSignerCertificate('Unsupported signer certificate version.');
        }

        foreach (['kid', 'publicKey', 'name', 'issuedAt', 'approvedBy'] as $field) {
            if (! is_string($p[$field] ?? null) || $p[$field] === '') {
                throw new BadSignerCertificate("Signer certificate has no {$field}.");
            }
        }

        try {
            $publicKey = Base64Url::decode($p['publicKey']);
        } catch (MalformedToken) {
            throw new BadSignerCertificate('Signer certificate publicKey is not base64url.');
        }

        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new BadSignerCertificate('Signer certificate publicKey must be 32 bytes.');
        }

        $expiresAt = $p['expiresAt'] ?? null;

        return new self(
            value: (string) preg_replace('/\s+/', '', $certificate),
            kid: $p['kid'],
            publicKey: $publicKey,
            name: $p['name'],
            issuedAt: self::date($p['issuedAt'], 'issuedAt'),
            expiresAt: is_string($expiresAt) && $expiresAt !== '' ? self::date($expiresAt, 'expiresAt') : null,
            approvedBy: $p['approvedBy'],
            signingInput: $parts['signingInput'],
            signature: $parts['signature'],
        );
    }

    /**
     * The till's checks: approvedBy is a configured approver, the approver's signature verifies, and the kid
     * is the SHA-256 kid of publicKey. With $tokenIssuedAt, the certificate must not have ended before it.
     *
     * @param  array<string, string>  $approvers  kid => base64url raw public key
     *
     * @throws BadSignerCertificate
     */
    public function check(array $approvers, ?CarbonInterface $tokenIssuedAt = null): self
    {
        $approverKey = $approvers[$this->approvedBy] ?? null;

        if (! is_string($approverKey) || $approverKey === '') {
            throw new BadSignerCertificate('Signer certificate is not from a configured approver.');
        }

        try {
            $approverKey = Base64Url::decode($approverKey);
        } catch (MalformedToken) {
            throw new BadSignerCertificate("Approver {$this->approvedBy} has an invalid public key in config.");
        }

        if (! SsposCodec::verify($this->signingInput, $this->signature, $approverKey)) {
            throw new BadSignerCertificate('Signer certificate signature does not verify.');
        }

        if (Kid::for($this->publicKey) !== $this->kid) {
            throw new BadSignerCertificate('Signer certificate kid does not match its public key.');
        }

        if ($tokenIssuedAt !== null && $this->expiresAt !== null && $tokenIssuedAt->greaterThan($this->expiresAt)) {
            throw new BadSignerCertificate('Signer certificate had ended when the token was issued.');
        }

        return $this;
    }

    /**
     * @throws BadSignerCertificate
     */
    public function assertFor(string $kid, string $publicKey): self
    {
        if ($this->kid !== $kid || ! hash_equals($this->publicKey, $publicKey)) {
            throw new BadSignerCertificate('Signer certificate is for another key.');
        }

        return $this;
    }

    /**
     * @throws BadSignerCertificate
     */
    private static function date(string $value, string $field): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            throw new BadSignerCertificate("Signer certificate {$field} is not a date.");
        }
    }
}
