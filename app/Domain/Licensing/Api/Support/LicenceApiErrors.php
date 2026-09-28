<?php

namespace App\Domain\Licensing\Api\Support;

use App\Domain\Licensing\Models\Licence;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\ApiDate;

/**
 * The licence API's error replies, codes and statuses exactly as contract v1.3.1 §17.12 and
 * licensing/samples/error-codes.json. Messages are en-GB for the shop owner; they never contain a key.
 */
final class LicenceApiErrors
{
    public const SUPPORT = 'Please contact Switch & Save support.';

    public static function invalid(string $message, ?string $field = null): ApiException
    {
        return new ApiException('request.invalid', 'The till sent a request we could not read. '.$message, 400, null, null, $field === null ? null : ['field' => $field]);
    }

    public static function contractUnsupported(): ApiException
    {
        return new ApiException('contract.unsupported', 'This version of the till is not supported. Please update SSPOS.', 409, null, null, ['supportedContracts' => [1]]);
    }

    public static function updateRequired(string $minimum): ApiException
    {
        return new ApiException('app.update_required', 'This till needs updating first. Settings > Updates > Check now.', 426, null, null, ['minimumAppVersion' => $minimum]);
    }

    public static function rateLimited(int $retryAfterSeconds): ApiException
    {
        return new ApiException('rate.limited', 'Too many requests. Please try again in a few minutes.', 429, max(1, $retryAfterSeconds));
    }

    public static function tooManyAttempts(int $retryAfterSeconds): ApiException
    {
        $minutes = max(1, (int) ceil($retryAfterSeconds / 60));

        return new ApiException('activation.too_many_attempts', "Too many wrong keys. Try again in {$minutes} ".($minutes === 1 ? 'minute.' : 'minutes.'), 429, max(1, $retryAfterSeconds));
    }

    public static function idempotencyMismatch(): ApiException
    {
        return new ApiException('request.idempotency_mismatch', 'This request reused an Idempotency-Key with a different body.', 422);
    }

    public static function inProgress(): ApiException
    {
        return new ApiException('request.in_progress', 'The same request is still being processed. Try again in a moment.', 409, 1);
    }

    public static function keyNotFound(): ApiException
    {
        return new ApiException('key.not_found', 'This licence key was not found. Check it was copied in full from the e-mail.', 404);
    }

    public static function keyExpired(): ApiException
    {
        return new ApiException('key.expired', 'This licence key can no longer be used. Ask your dealer for a new key.', 410);
    }

    public static function keyAlreadyUsed(Licence $licence): ApiException
    {
        $name = $licence->device_name ?? 'another PC';

        return new ApiException('key.already_used', "This licence key is already in use on another PC ({$name}). Ask your dealer to release it.", 409, null, null, [
            'deviceName' => $licence->device_name,
            'installCode' => $licence->install_code,
            'activatedAtUtc' => ApiDate::format($licence->bound_at),
        ]);
    }

    public static function notActive(string $status, ?string $reason): ApiException
    {
        $reason = trim((string) $reason);

        return new ApiException('licence.not_active', ($reason === '' ? 'This licence cannot be used right now.' : $reason).' '.self::SUPPORT, 403, null, null, ['status' => $status]);
    }

    public static function seatLimit(int $maxRegisters, int $inUse): ApiException
    {
        $tills = $maxRegisters === 1 ? '1 till' : "{$maxRegisters} tills";

        return new ApiException('licence.seat_limit', "Your plan allows {$tills} in this branch and {$inUse} ".($inUse === 1 ? 'is' : 'are').' in use. Release a till or add one on the portal.', 403, null, null, [
            'maxRegisters' => $maxRegisters,
            'registersInUse' => $inUse,
        ]);
    }

    public static function deviceNotFound(): ApiException
    {
        return new ApiException('device.not_found', 'This till is not linked to a licence on the portal.', 404);
    }
}
