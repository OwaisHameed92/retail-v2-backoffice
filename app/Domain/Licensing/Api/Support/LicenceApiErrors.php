<?php

namespace App\Domain\Licensing\Api\Support;

use App\Domain\Shared\Exceptions\ApiException;

/**
 * The licence API's error replies (docs/specs/licence-api-v1.md, "Errors"). Messages are en-GB, for the shop
 * owner reading the till screen. They never contain the key.
 */
final class LicenceApiErrors
{
    public const NOT_FOUND = 'licence.not_found';

    public const BOUND_TO_OTHER_DEVICE = 'licence.bound_to_other_device';

    public const DEVICE_MISMATCH = 'licence.device_mismatch';

    public const REVOKED = 'licence.revoked';

    public const NOT_ACTIVATABLE = 'licence.not_activatable';

    public const CONTRACT_UNSUPPORTED = 'contract.unsupported';

    public const SUPPORT = 'Please contact Switch & Save support.';

    public static function notFound(): ApiException
    {
        return ApiException::notFound(self::NOT_FOUND, 'We could not find this licence key. Check it and try again.');
    }

    public static function boundToOtherDevice(): ApiException
    {
        return ApiException::conflict(self::BOUND_TO_OTHER_DEVICE, 'This licence key is already in use on another PC. To move it to this PC, '.lcfirst(self::SUPPORT));
    }

    public static function deviceMismatch(bool $bound): ApiException
    {
        return ApiException::forbidden(self::DEVICE_MISMATCH, $bound
            ? 'This licence key is now in use on another PC, so this till cannot renew its licence. '.self::SUPPORT
            : 'This till needs to be activated again. Enter the licence key to activate it.');
    }

    public static function revoked(): ApiException
    {
        return ApiException::forbidden(self::REVOKED, 'This licence key has been cancelled and cannot be used. '.self::SUPPORT);
    }

    public static function notActivatable(?string $reason): ApiException
    {
        $reason = trim((string) $reason);

        return ApiException::forbidden(self::NOT_ACTIVATABLE, ($reason === '' ? 'This licence cannot be activated right now.' : $reason).' '.self::SUPPORT);
    }

    public static function contractUnsupported(): ApiException
    {
        return ApiException::conflict(self::CONTRACT_UNSUPPORTED, 'This version of the till is not supported. Please update SSPOS.');
    }

    public static function keyInAddress(): ApiException
    {
        return ApiException::invalid('The till sent a request we could not read. The licence key must be sent in the request body.');
    }
}
