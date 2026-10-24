<?php

namespace App\Domain\OnlineStore\Payments;

use SensitiveParameter;

/**
 * Checks that a payment result really came from DNA (the hospitality DnaGateway::verifySignature, the same HMAC as
 * DNA's SDK): base64 HMAC-SHA256 over id + amount + currency + invoiceId + errorCode + json(success), keyed on the
 * store's client secret, compared in constant time. A result missing any of those fields fails.
 */
final class DnaSignature
{
    public const FIELDS = ['id', 'amount', 'currency', 'invoiceId', 'errorCode', 'success', 'signature'];

    /**
     * @param  array<string, mixed>  $result
     */
    public static function verify(array $result, #[SensitiveParameter] string $secret): bool
    {
        foreach (self::FIELDS as $key) {
            if (! array_key_exists($key, $result)) {
                return false;
            }
        }

        return is_string($result['signature']) && hash_equals(self::sign($result, $secret), $result['signature']);
    }

    /**
     * The signature DNA puts on a result (tests use it to fake DNA's callback).
     *
     * @param  array<string, mixed>  $result
     */
    public static function sign(array $result, #[SensitiveParameter] string $secret): string
    {
        $string = self::text($result['id'] ?? '').self::text($result['amount'] ?? '').self::text($result['currency'] ?? '')
            .self::text($result['invoiceId'] ?? '').self::text($result['errorCode'] ?? '').json_encode($result['success'] ?? null);

        return base64_encode(hash_hmac('sha256', $string, $secret, true));
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
