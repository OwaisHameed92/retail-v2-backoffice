<?php

namespace App\Domain\Licensing\Signing;

use App\Domain\Licensing\Signing\Exceptions\MalformedToken;
use JsonException;

/**
 * Framework-free JWS compact serialisation with EdDSA over Ed25519 (RFC 7515 + RFC 8037), on libsodium.
 *
 * Knows nothing about keys in the database, kids or claims: `LicenceTokenSigner` / `LicenceTokenVerifier`
 * add those rules. Kept pure so the RFC 8037 appendix A vector can be tested byte for byte.
 */
final class Ed25519Jws
{
    public const ALG = 'EdDSA';

    /** Hard cap on token size before any parsing, so a hostile input cannot cost much. */
    public const MAX_TOKEN_BYTES = 8192;

    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /**
     * @param  array<string, mixed>  $header
     * @param  string  $payload  Raw payload bytes (already JSON-encoded for licence tokens).
     * @param  string  $secretKey  64-byte libsodium secret key (seed + public key).
     */
    public static function sign(array $header, string $payload, #[\SensitiveParameter] string $secretKey): string
    {
        if (strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \InvalidArgumentException('Ed25519 secret key must be 64 bytes.');
        }

        $signingInput = Base64Url::encode(json_encode($header, self::JSON_FLAGS)).'.'.Base64Url::encode($payload);
        $signature = sodium_crypto_sign_detached($signingInput, $secretKey);

        return $signingInput.'.'.Base64Url::encode($signature);
    }

    /**
     * Splits and decodes a compact JWS. Does NOT check the signature.
     *
     * @return array{header: array<string, mixed>, payload: string, signature: string, signingInput: string}
     *
     * @throws MalformedToken
     */
    public static function parse(string $token): array
    {
        if ($token === '' || strlen($token) > self::MAX_TOKEN_BYTES) {
            throw new MalformedToken('Token is empty or too long.');
        }

        $parts = explode('.', $token);

        if (count($parts) !== 3 || in_array('', $parts, true)) {
            throw new MalformedToken('Token must have three non-empty segments.');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = self::decodeJsonObject(Base64Url::decode($headerB64), 'header');
        $signature = Base64Url::decode($signatureB64);

        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new MalformedToken('Signature must be 64 bytes.');
        }

        return [
            'header' => $header,
            'payload' => Base64Url::decode($payloadB64),
            'signature' => $signature,
            'signingInput' => $headerB64.'.'.$payloadB64,
        ];
    }

    /**
     * @param  string  $publicKey  32-byte Ed25519 public key.
     */
    public static function verify(string $signingInput, string $signature, string $publicKey): bool
    {
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $signingInput, $publicKey);
    }

    /**
     * @return array{public: string, secret: string}
     */
    public static function keyPairFromSeed(#[\SensitiveParameter] string $seed): array
    {
        $pair = sodium_crypto_sign_seed_keypair($seed);

        return [
            'public' => sodium_crypto_sign_publickey($pair),
            'secret' => sodium_crypto_sign_secretkey($pair),
        ];
    }

    /**
     * @return array{public: string, secret: string}
     */
    public static function newKeyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'public' => sodium_crypto_sign_publickey($pair),
            'secret' => sodium_crypto_sign_secretkey($pair),
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MalformedToken
     */
    public static function decodeJsonObject(string $json, string $what): array
    {
        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new MalformedToken("Token {$what} is not valid JSON.");
        }

        // Must be a JSON object: `{}` decodes to [] too, so also require the text to start with "{".
        if (! is_array($decoded) || ! str_starts_with(ltrim($json), '{') || (array_is_list($decoded) && $decoded !== [])) {
            throw new MalformedToken("Token {$what} must be a JSON object.");
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
