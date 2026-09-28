<?php

namespace App\Domain\Licensing\Signing\Sspos;

use App\Domain\Licensing\Signing\SigningKey;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * The public-key hand-over for the SSPOS owner (contract §17.2/§17.17,
 * licensing/samples/public-key-handover.json). Public key only; the reply is a signer certificate.
 */
final class PublicKeyHandover
{
    /** DER prefix of an Ed25519 SubjectPublicKeyInfo (RFC 8410); the raw 32-byte key follows. */
    private const SPKI_PREFIX = '302a300506032b6570032100';

    public function __construct(private readonly Config $config) {}

    /**
     * @return array{kid: string, alg: string, publicKeyBase64Url: string, publicKeyPem: string, notBefore: string, contact: string, notes: string}
     */
    public function for(SigningKey $key): array
    {
        return [
            'kid' => $key->kid,
            'alg' => 'Ed25519',
            'publicKeyBase64Url' => $key->x(),
            'publicKeyPem' => self::pem($key->publicKey),
            'notBefore' => LicenceClaims::utc($key->createdAt),
            'contact' => (string) $this->config->get('licence.handover_contact', ''),
            'notes' => 'Send this to the SSPOS owner over a trusted channel. Never send the private key. You get back a signer certificate (SSPOSCERT1...): put it in every token as signerCert (docs/web-portal-api.md 17.17). No till release is needed.',
        ];
    }

    public static function pem(string $publicKey): string
    {
        $der = (string) hex2bin(self::SPKI_PREFIX).$publicKey;

        return "-----BEGIN PUBLIC KEY-----\n".base64_encode($der)."\n-----END PUBLIC KEY-----";
    }
}
