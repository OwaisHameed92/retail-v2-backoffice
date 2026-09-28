<?php

namespace App\Domain\Shared\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The scheduler stamps the time every minute (routes/console.php) so the admin dashboard can show whether
 * scheduled work (billing:run, licences:refresh) is actually running.
 */
final class SchedulerHeartbeat
{
    public const KEY = 'scheduler:last-run';

    public static function beat(?CarbonImmutable $now = null): void
    {
        Cache::forever(self::KEY, ($now ?? CarbonImmutable::now())->utc()->toIso8601String());
    }

    public static function lastRun(): ?CarbonImmutable
    {
        $value = Cache::get(self::KEY);

        return is_string($value) ? CarbonImmutable::parse($value) : null;
    }
}
