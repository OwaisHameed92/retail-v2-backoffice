<?php

namespace App\Domain\Salon\Support;

use App\Domain\Shared\Exceptions\ApiException;

/**
 * The salon calls' error codes (salon pack 2026-11-08, `salon/error-codes.additions.json`; W2 records them, W3's five
 * `sync/salon/*` calls throw them). The body is the usual `{code, message, traceId, retryAfterSeconds, rejectedKey}`;
 * messages are en-GB for the shop owner (the till shows them on Salon / Barber → Online booking → Status) and never
 * contain a key, a token or a client's details.
 */
final class SalonApiErrors
{
    public const NOT_AVAILABLE = 'salon.not_available';

    public const PAGE_TOO_LARGE = 'salon.page_too_large';

    public const JOIN_NOT_FOUND = 'salon.join_not_found';

    public const NOT_A_SALON = 'salon.not_a_salon';

    /** code => HTTP status, exactly as error-codes.additions.json (a test keeps them equal). */
    public const STATUS = [
        self::NOT_AVAILABLE => 501,
        self::NOT_A_SALON => 403,
        self::PAGE_TOO_LARGE => 413,
        self::JOIN_NOT_FOUND => 404,
    ];

    /** The till waits an hour (or Retry-After if longer) before its next salon call; push and pull carry on. */
    public const NOT_AVAILABLE_RETRY_SECONDS = 3600;

    public static function notAvailable(): ApiException
    {
        return new ApiException(self::NOT_AVAILABLE, 'Online booking and queue join from a phone are not switched on for salons yet. The till will try again later; sales and sync carry on.', 501, self::NOT_AVAILABLE_RETRY_SECONDS);
    }

    public static function notASalon(): ApiException
    {
        return new ApiException(self::NOT_A_SALON, 'This shop is not a Salon / Barber shop on the portal. Please call support.', 403);
    }

    public static function pageTooLarge(): ApiException
    {
        return new ApiException(self::PAGE_TOO_LARGE, 'The booking page is too large. Lower "Book up to (days ahead)" on the Online booking tab.', 413);
    }

    public static function joinNotFound(string $joinId): ApiException
    {
        return new ApiException(self::JOIN_NOT_FOUND, 'The portal has no phone join with this id for this shop. The till moves on.', 404, null, $joinId);
    }
}
