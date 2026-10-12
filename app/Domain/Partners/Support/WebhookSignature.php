<?php

namespace App\Domain\Partners\Support;

use SensitiveParameter;

/**
 * `X-Partner-Signature: t=<unix>,v1=<hex HMAC-SHA256 of "<t>.<raw body>" with the partner's secret>`
 * (docs/partner-api.md "Webhooks"). The partner rejects a signature older than 5 minutes; `verify()` is the same
 * check, used by our tests and handy for a partner's reference code.
 */
final class WebhookSignature
{
    public const HEADER = 'X-Partner-Signature';

    public const TOLERANCE_SECONDS = 300;

    public static function header(#[SensitiveParameter] string $secret, string $body, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.self::hmac($secret, $body, $timestamp);
    }

    public static function verify(#[SensitiveParameter] string $secret, string $body, string $header, int $now, int $tolerance = self::TOLERANCE_SECONDS): bool
    {
        $parts = [];

        foreach (explode(',', $header) as $pair) {
            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            $parts[$name] = $value;
        }

        if (! isset($parts['t'], $parts['v1']) || ! ctype_digit($parts['t'])) {
            return false;
        }

        $timestamp = (int) $parts['t'];

        return abs($now - $timestamp) <= $tolerance && hash_equals(self::hmac($secret, $body, $timestamp), $parts['v1']);
    }

    private static function hmac(#[SensitiveParameter] string $secret, string $body, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
