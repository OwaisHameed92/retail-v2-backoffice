<?php

namespace App\Domain\Handheld\Support;

use App\Domain\Shared\Exceptions\ApiException;

/**
 * Errors of the handheld (PDA) API. Codes are UPPER_SNAKE like the legacy PDA API (the app branches on the top-level
 * `code`); the body is `{success: false, code, message, traceId, retryAfterSeconds, rejectedKey}` (PdaErrorRenderer).
 * docs/pda-api.md "Errors" lists them.
 */
final class PdaErrors
{
    public const INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';

    public const UNAUTHENTICATED = 'UNAUTHENTICATED';

    public const ACCOUNT_INACTIVE = 'ACCOUNT_INACTIVE';

    public const SHOP_CLOSED = 'SHOP_CLOSED';

    public const BUSINESS_SUSPENDED = 'BUSINESS_SUSPENDED';

    public const BUSINESS_CANCELLED = 'BUSINESS_CANCELLED';

    public const TENANT_REQUIRED = 'TENANT_REQUIRED';

    public const RATE_LIMITED = 'RATE_LIMITED';

    public const VALIDATION_FAILED = 'VALIDATION_FAILED';

    public const NOT_FOUND = 'NOT_FOUND';

    public const FORBIDDEN = 'FORBIDDEN';

    public const METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';

    public const SERVER_ERROR = 'SERVER_ERROR';

    public const SERVER_BUSY = 'SERVER_BUSY';

    public const PRICE_CHANGE_NOT_ALLOWED = 'PRICE_CHANGE_NOT_ALLOWED';

    public const PRODUCT_ARCHIVED = 'PRODUCT_ARCHIVED';

    public const PO_OWNED_BY_TILL = 'PO_OWNED_BY_TILL';

    public const PO_CANCELLED = 'PO_CANCELLED';

    public const NOT_AVAILABLE_YET = 'NOT_AVAILABLE_YET';

    public static function invalidCredentials(): ApiException
    {
        return new ApiException(self::INVALID_CREDENTIALS, 'The email or password is wrong.', 401);
    }

    public static function unauthenticated(): ApiException
    {
        return new ApiException(self::UNAUTHENTICATED, 'Sign in again: send a valid token as "Authorization: Bearer <token>".', 401);
    }

    public static function inactive(): ApiException
    {
        return new ApiException(self::ACCOUNT_INACTIVE, 'This handheld account is switched off. Ask your manager to reactivate it on the portal.', 403);
    }

    public static function shopClosed(): ApiException
    {
        return new ApiException(self::SHOP_CLOSED, 'The shop this handheld account belongs to is closed. Ask your manager to move it to an open shop.', 403);
    }

    public static function suspended(): ApiException
    {
        return new ApiException(self::BUSINESS_SUSPENDED, 'This business’s account is on hold. The business owner can sort it out on the portal.', 403);
    }

    public static function cancelled(): ApiException
    {
        return new ApiException(self::BUSINESS_CANCELLED, 'This business’s account is closed.', 403);
    }

    public static function tenantRequired(): ApiException
    {
        return new ApiException(self::TENANT_REQUIRED, 'This email signs in to more than one business. Send "tenant_id" with the login, or ask your manager for a separate email.', 409);
    }

    public static function rateLimited(int $retryAfterSeconds): ApiException
    {
        $seconds = max(1, $retryAfterSeconds);

        return new ApiException(self::RATE_LIMITED, "Too many requests. Try again in {$seconds} seconds.", 429, $seconds);
    }

    public static function notFound(string $what = 'record'): ApiException
    {
        return new ApiException(self::NOT_FOUND, "We could not find this {$what} in your shop.", 404);
    }

    public static function priceChangeNotAllowed(): ApiException
    {
        return new ApiException(self::PRICE_CHANGE_NOT_ALLOWED, 'This handheld account may not change prices. Ask your manager to allow it on the portal.', 403);
    }

    public static function productArchived(): ApiException
    {
        return new ApiException(self::PRODUCT_ARCHIVED, 'This product is archived. Restore it on the portal before changing its price.', 422);
    }

    public static function ownedByTill(string $message): ApiException
    {
        return new ApiException(self::PO_OWNED_BY_TILL, $message, 409);
    }

    public static function orderCancelled(): ApiException
    {
        return new ApiException(self::PO_CANCELLED, 'A cancelled order cannot be changed or re-opened. Create a new order instead.', 409);
    }

    public static function notAvailableYet(): ApiException
    {
        return new ApiException(self::NOT_AVAILABLE_YET, 'This is done on the shop’s till for now. It comes to the handheld app in phase 2.', 501);
    }
}
