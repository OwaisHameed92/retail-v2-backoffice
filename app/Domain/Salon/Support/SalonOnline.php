<?php

namespace App\Domain\Salon\Support;

use App\Domain\Tenancy\Models\Company;

/**
 * The salon W3 switch (`SALON_ONLINE`, config `salon.online`, default off; off in production until the till's salon
 * release). Off: the staff calendar feeds answer 404, the tenant "Salon" page and its menu item are hidden (its
 * routes 404) and the admin tenant page has no salon summary.
 *
 * `availableFor()`: the switch is on and the business has a salon shop (SalonShops). A grocery never sees any of it.
 */
final class SalonOnline
{
    public static function enabled(): bool
    {
        return (bool) config('salon.online', false);
    }

    public static function availableFor(Company $company): bool
    {
        return self::enabled() && SalonShops::anySalon($company->id);
    }
}
