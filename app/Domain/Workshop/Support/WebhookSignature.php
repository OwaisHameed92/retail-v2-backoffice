<?php

namespace App\Domain\Workshop\Support;

use SensitiveParameter;

/**
 * Signature of a webhook to BikeBook (WORKSHOP-BIKEBOOK.md §3.8): `X-Pos-Signature: Base64(HMAC_SHA256(secret,
 * raw_body))`, the secret being the one BikeBook gave when it created its webhook on us.
 */
final class WebhookSignature
{
    public const HEADER = 'X-Pos-Signature';

    public static function sign(#[SensitiveParameter] string $secret, string $body): string
    {
        return base64_encode(hash_hmac('sha256', $body, $secret, true));
    }

    public static function verify(#[SensitiveParameter] string $secret, string $body, string $signature): bool
    {
        return hash_equals(self::sign($secret, $body), $signature);
    }
}
