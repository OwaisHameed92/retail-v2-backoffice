<?php

namespace App\Domain\Salon\Online;

use App\Domain\Salon\Models\SalonOnlineShop;
use SensitiveParameter;

/**
 * The manage link's token (W3a, salon pack README §8): the first 22 characters of
 * base64url(HMAC-SHA256(key = UTF-8 of `manageLink.key`, message = UTF-8 of `appointmentId`)), no padding. The till
 * makes the same link for every appointment message; we make it for appointments booked online.
 *
 * Checked in constant time against the shop's newest key only: a link made with an earlier key (another `keyId`)
 * no longer opens, and a shop whose till never sent a key opens none. Never logged, never put in an analytics URL.
 */
final class ManageLinkToken
{
    public const LENGTH = 22;

    public static function make(#[SensitiveParameter] string $key, string $appointmentId): string
    {
        return substr(rtrim(strtr(base64_encode(hash_hmac('sha256', $appointmentId, $key, true)), '+/', '-_'), '='), 0, self::LENGTH);
    }

    public static function valid(SalonOnlineShop $shop, string $appointmentId, #[SensitiveParameter] string $token): bool
    {
        $key = (string) $shop->manage_key;

        if ($key === '' || strlen($token) !== self::LENGTH || preg_match('/^[A-Za-z0-9_-]+$/', $token) !== 1) {
            return false;
        }

        return hash_equals(self::make($key, $appointmentId), $token);
    }

    /** The whole link for an appointment of this shop, or null when the shop has no key. */
    public static function link(SalonOnlineShop $shop, string $appointmentId): ?string
    {
        $key = (string) $shop->manage_key;

        return $key === '' ? null : SalonOnlineShops::addresses($shop)['manageUrl'].$appointmentId.'.'.self::make($key, $appointmentId);
    }
}
