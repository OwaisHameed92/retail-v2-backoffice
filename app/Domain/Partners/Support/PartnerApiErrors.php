<?php

namespace App\Domain\Partners\Support;

use App\Domain\Shared\Exceptions\ApiException;

/**
 * Errors of the partner API, rendered in the portal's envelope `{code, message, traceId, retryAfterSeconds,
 * rejectedKey}` (+ `details` for validation). docs/partner-api.md "Errors" lists them.
 */
final class PartnerApiErrors
{
    // The partner API's codes (docs/partner-api.md "Errors"). Not till codes: the EPOS contract's error-codes.json
    // (tests/Feature/Contract/ErrorCodesTest) covers the till endpoints only; partners never call those.
    public const INVALID_KEY = 'auth.invalid_key';

    public const KEY_REVOKED = 'auth.key_revoked';

    public const RATE_LIMITED = 'rate.limited';

    public const NOT_FOUND = 'partner.request_not_found';

    public const LOCKED = 'partner.request_locked';

    public const CLOSED = 'partner.request_closed';

    public const INVALID = 'request.invalid';

    public static function invalidKey(): ApiException
    {
        return new ApiException(self::INVALID_KEY, 'Send a valid partner key as "Authorization: Bearer <key>".', 401);
    }

    public static function keyRevoked(): ApiException
    {
        return new ApiException(self::KEY_REVOKED, 'This partner key was revoked. Ask Switch & Save for a new key.', 401);
    }

    public static function rateLimited(int $retryAfterSeconds): ApiException
    {
        $seconds = max(1, $retryAfterSeconds);

        return new ApiException(self::RATE_LIMITED, "Too many requests with this partner key. Try again in {$seconds} seconds.", 429, $seconds);
    }

    public static function notFound(): ApiException
    {
        return new ApiException(self::NOT_FOUND, 'There is no tenant request with this externalId.', 404);
    }

    public static function locked(string $message): ApiException
    {
        return new ApiException(self::LOCKED, $message, 409);
    }

    public static function closed(): ApiException
    {
        return new ApiException(self::CLOSED, 'This request was cancelled. Send the deal again with a new externalId.', 409);
    }

    /**
     * 422 `request.invalid`: the first message, and every message per dotted body path in `details.fields`.
     *
     * @param  array<string, list<string>>  $errors
     */
    public static function invalid(array $errors): ApiException
    {
        $first = array_key_first($errors);
        $message = $first === null ? null : ($errors[$first][0] ?? null);

        return new ApiException(self::INVALID, trim('The request is not valid. '.($message ?? '')), 422, null, null, ['fields' => $errors]);
    }
}
