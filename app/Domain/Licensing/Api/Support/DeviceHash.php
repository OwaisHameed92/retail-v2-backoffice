<?php

namespace App\Domain\Licensing\Api\Support;

use RuntimeException;
use SensitiveParameter;

/**
 * HMAC-SHA256 (hex) of a till's device id, keyed with APP_KEY: how the portal remembers PCs that are not (or no
 * longer) the bound one, without keeping their ids. Rotating APP_KEY only makes older history stop matching.
 */
final class DeviceHash
{
    public static function of(#[SensitiveParameter] string $deviceId): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new RuntimeException('APP_KEY is not set: device ids cannot be hashed.');
        }

        $secret = str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key;

        return hash_hmac('sha256', 'sspos-device|'.$deviceId, $secret);
    }

    /** "…4F2A": the last 4 characters of a device id, enough for staff to tell two PCs apart. */
    public static function ending(?string $deviceId): ?string
    {
        return $deviceId === null || $deviceId === '' ? null : '…'.mb_substr($deviceId, -4);
    }
}
