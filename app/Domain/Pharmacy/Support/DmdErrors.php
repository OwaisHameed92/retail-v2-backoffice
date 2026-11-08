<?php

namespace App\Domain\Pharmacy\Support;

use App\Domain\Shared\Exceptions\ApiException;

/**
 * The dm+d release server's errors (W4), in the §9 body `{code, message, traceId, retryAfterSeconds, rejectedKey}`.
 * PORTAL-CHANGES-pharmacy.md item 12 names only `dmd.no_release` (404); the 403 and 501 codes are ours (docs/DECISIONS.md)
 * and the till treats them by status: 403 → TRUD + "check Cloud sync", 501 / 5xx / 429 → again in an hour.
 * Machine-readable list: docs/contracts/proposals/pharmacy-dmd/error-codes.additions.json.
 */
final class DmdErrors
{
    public static function notAvailable(): ApiException
    {
        return new ApiException('dmd.not_available', 'The medicines dictionary (dm+d) is not served by the portal yet. The till uses NHS TRUD and asks again later.', 501, DmdSwitch::retryAfter());
    }

    public static function notPharmacy(): ApiException
    {
        return new ApiException('dmd.not_pharmacy', 'The medicines dictionary (dm+d) is only for shops set up as a pharmacy.', 403);
    }

    public static function noRelease(): ApiException
    {
        return new ApiException('dmd.no_release', 'No medicines dictionary (dm+d) release is published yet. The till uses NHS TRUD and asks again later.', 404);
    }

    public static function linkInvalid(): ApiException
    {
        return new ApiException('dmd.link_invalid', 'This download link is not valid or has expired. The till asks the portal for a new one.', 403);
    }

    public static function rateLimited(int $retryAfterSeconds): ApiException
    {
        return new ApiException('rate.limited', 'Too many medicines dictionary requests. The till will try again shortly.', 429, max(1, $retryAfterSeconds));
    }
}
