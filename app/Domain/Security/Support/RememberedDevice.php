<?php

namespace App\Domain\Security\Support;

use App\Domain\Security\Contracts\TwoFactorUser;
use App\Domain\Security\Enums\TwoFactorArea;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * "Remember this device for 30 days": a signed cookie `{id}|{expires}|{hmac}`. The HMAC (app key) covers the guard,
 * the account, the expiry and the account's two-factor stamp, so a reset or a new secret forgets every device.
 * The cookie is also encrypted by Laravel's EncryptCookies.
 */
final class RememberedDevice
{
    public const DAYS = 30;

    public static function cookieName(TwoFactorArea $area): string
    {
        return $area === TwoFactorArea::Admin ? 'sspos_admin_2fa_device' : 'sspos_2fa_device';
    }

    public static function issue(TwoFactorArea $area, TwoFactorUser $user): Cookie
    {
        $expires = now()->addDays(self::DAYS)->getTimestamp();
        $id = (string) $user->getKey();
        $value = $id.'|'.$expires.'|'.self::sign($area, $user, $expires);

        return cookie(self::cookieName($area), $value, self::DAYS * 24 * 60, null, null, null, true, false, 'lax');
    }

    public static function forget(TwoFactorArea $area): Cookie
    {
        return cookie()->forget(self::cookieName($area));
    }

    public static function valid(Request $request, TwoFactorArea $area, TwoFactorUser $user): bool
    {
        $value = $request->cookie(self::cookieName($area));

        if (! is_string($value) || substr_count($value, '|') !== 2) {
            return false;
        }

        [$id, $expires, $signature] = explode('|', $value);

        if ($id !== (string) $user->getKey() || ! ctype_digit($expires) || (int) $expires < now()->getTimestamp()) {
            return false;
        }

        return hash_equals(self::sign($area, $user, (int) $expires), $signature);
    }

    private static function sign(TwoFactorArea $area, TwoFactorUser $user, int $expires): string
    {
        $payload = implode('|', [$area->value, (string) $user->getKey(), $expires, $user->twoFactorStamp()]);

        return hash_hmac('sha256', $payload, 'remembered-device|'.config('app.key'));
    }
}
