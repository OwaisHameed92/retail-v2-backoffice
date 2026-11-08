<?php

namespace App\Domain\Salon\Support;

/**
 * The `salon_online` switch (env `SALON_ONLINE`, default off; stays off in production until the owner says so) and its
 * limits (W3a, salon pack 2026-11-08 README §3, §4). Off: every `sync/salon/*` call answers 501 salon.not_available
 * with Retry-After 3600 after the key check and before the body is read, and every public salon page is a 404.
 */
final class SalonOnlineSwitch
{
    public static function enabled(): bool
    {
        return (bool) config('salon_online.enabled', false);
    }

    public static function perMinute(): int
    {
        return max(6, (int) config('salon_online.per_minute', 60));
    }

    public static function maxPageBytes(): int
    {
        return max(1024, (int) config('salon_online.max_page_bytes', 8 * 1024 * 1024));
    }

    public static function joinTtlMinutes(): int
    {
        return max(1, (int) config('salon_online.join_ttl_minutes', 15));
    }

    public static function defaultOfflineAfterMin(): int
    {
        return max(1, (int) config('salon_online.default_offline_after_min', 10));
    }

    public static function public(string $key, int $default): int
    {
        return max(1, (int) config("salon_online.public.{$key}", $default));
    }
}
