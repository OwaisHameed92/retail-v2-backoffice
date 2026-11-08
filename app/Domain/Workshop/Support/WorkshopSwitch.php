<?php

namespace App\Domain\Workshop\Support;

/**
 * The `workshop.connector` switch (env WORKSHOP_CONNECTOR, default off; stays off in production until BikeBook
 * registers SSPOS and the till applies `WorkshopOrder`). Every connector code path asks this first, before any query,
 * so with the switch off nothing reads or writes a workshop table and every pull is byte-identical.
 */
final class WorkshopSwitch
{
    public static function enabled(): bool
    {
        return (bool) config('workshop.connector', false);
    }

    /** Whether a till reporting this app version may get `WorkshopOrder` rows (switch on, and new enough). */
    public static function servesTill(string $appVersion): bool
    {
        if (! self::enabled()) {
            return false;
        }

        $minimum = trim((string) config('workshop.connector_min_till', ''));

        return $minimum === '' || ($appVersion !== '' && version_compare($appVersion, $minimum, '>='));
    }
}
