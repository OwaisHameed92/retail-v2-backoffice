<?php

namespace App\Domain\Licensing\Signing\Sspos;

use App\Domain\Licensing\Signing\Base64Url;
use App\Domain\Licensing\Signing\Exceptions\MalformedToken;
use JsonException;

/**
 * The shared `<PREFIX>.<base64url(payload JSON)>.<base64url(Ed25519 signature)>` format of licence tokens
 * (`SSPOS1`, contract §17.2) and signer certificates (`SSPOSCERT1`, §17.17).
 *
 * The signature covers the ASCII bytes of "<PREFIX>." + payload part. Verification always uses the payload
 * part exactly as received: the JSON is never re-serialised.
 */
final class SsposCodec
{
    public const TOKEN_PREFIX = 'SSPOS1';

    public const CERT_PREFIX = 'SSPOSCERT1';

    /** Compact UTF-8 JSON, as the contract's PHP reference snippet writes it. */
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function sign(string $prefix, array $payload, #[\SensitiveParameter] string $secretKey): string
    {
        $input = $prefix.'.'.Base64Url::encode(json_encode($payload, self::JSON_FLAGS));

        return $input.'.'.Base64Url::encode(sodium_crypto_sign_detached($input, $secretKey));
    }

    /**
     * Splits and decodes a token or certificate. Whitespace is stripped first (the till accepts pasted line
     * breaks). The payload is untrusted until `verify()` has passed.
     *
     * @return array{payload: array<string, mixed>, signingInput: string, signature: string}
     *
     * @throws MalformedToken
     */
    public static function parse(string $prefix, string $value): array
    {
        $value = (string) preg_replace('/\s+/', '', $value);
        $parts = explode('.', $value);

        if (count($parts) !== 3 || $parts[0] !== $prefix || $parts[1] === '' || $parts[2] === '') {
            throw new MalformedToken("Not a {$prefix} value: expected {$prefix}.<payload>.<signature>.");
        }

        $signature = Base64Url::decode($parts[2]);

        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new MalformedToken('Signature must be 64 bytes.');
        }

        return [
            'payload' => self::decodeObject(Base64Url::decode($parts[1])),
            'signingInput' => $prefix.'.'.$parts[1],
            'signature' => $signature,
        ];
    }

    public static function verify(string $signingInput, string $signature, string $publicKey): bool
    {
        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($signature, $signingInput, $publicKey);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws MalformedToken
     */
    private static function decodeObject(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new MalformedToken('Payload is not valid JSON.');
        }

        if (! is_array($decoded) || ! str_starts_with(ltrim($json), '{') || (array_is_list($decoded) && $decoded !== [])) {
            throw new MalformedToken('Payload must be a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
