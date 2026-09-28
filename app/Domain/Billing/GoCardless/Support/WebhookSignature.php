<?php

namespace App\Domain\Billing\GoCardless\Support;

/**
 * GoCardless signs each webhook body with HMAC-SHA256 using the endpoint's secret (`Webhook-Signature` header).
 * No secret configured = every request is refused.
 */
final class WebhookSignature
{
    public static function valid(string $body, ?string $signature): bool
    {
        $secret = (string) config('services.gocardless.webhook_secret');

        if ($secret === '' || $signature === null || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $body, $secret), $signature);
    }

    public static function sign(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }
}
