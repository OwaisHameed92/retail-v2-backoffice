<?php

namespace App\Domain\Salon\Support;

/**
 * W3a's side of the salon switch (`SALON_ONLINE`, config `salon.online`, SalonOnline; default off, off in production
 * until the owner says so) and W3a's limits in `config/salon.php` (salon pack 2026-11-08 README §3, §4). Off: every
 * `sync/salon/*` call answers 501 salon.not_available with Retry-After 3600 after the key check and before the body is
 * read, and every public salon page is a 404.
 */
final class SalonOnlineSwitch
{
    public static function enabled(): bool
    {
        return SalonOnline::enabled();
    }

    public static function perMinute(): int
    {
        return max(6, (int) config('salon.online_per_minute', 60));
    }

    public static function maxPageBytes(): int
    {
        return max(1024, (int) config('salon.max_page_bytes', 8 * 1024 * 1024));
    }

    public static function joinTtlMinutes(): int
    {
        return max(1, (int) config('salon.join_ttl_minutes', 15));
    }

    public static function defaultOfflineAfterMin(): int
    {
        return max(1, (int) config('salon.default_offline_after_min', 10));
    }

    public static function public(string $key, int $default): int
    {
        return max(1, (int) config("salon.public.{$key}", $default));
    }
}
