<?php

namespace App\Domain\TillHealth\Support;

use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\TillProfile;

/**
 * The Till health thresholds of config/till-health.php (module 2.7), plus the minimum app version the licence API
 * already sends (`licence.api.minimum_app_version`), so both use one number.
 */
final readonly class HealthThresholds
{
    /** The shops' time zone for trading hours and alert times: by default the country profile's (phase P1). */
    public string $timezone;

    public function __construct(
        public int $syncOnlineMinutes = 15,
        public int $syncOfflineHours = 4,
        public int $validateOnlineHours = 26,
        public int $validateOfflineHours = 72,
        public int $clockSkewSeconds = 300,
        public int $syncFailingHours = 24,
        public int $syncStalledHours = 24,
        public int $alertOfflineHours = 4,
        public string $minimumAppVersion = '0.1.0',
        public string $tradingStart = '08:00',
        public string $tradingEnd = '20:00',
        ?string $timezone = null,
        public int $refreshMinutes = 5,
    ) {
        $this->timezone = $timezone !== null && $timezone !== '' ? $timezone : Country::zone();
    }

    public static function fromConfig(): self
    {
        $hours = (array) config('till-health.trading_hours', []);

        return new self(
            syncOnlineMinutes: max(1, (int) config('till-health.sync_online_minutes', 15)),
            syncOfflineHours: max(1, (int) config('till-health.sync_offline_hours', 4)),
            validateOnlineHours: max(1, (int) config('till-health.validate_online_hours', 26)),
            validateOfflineHours: max(1, (int) config('till-health.validate_offline_hours', 72)),
            clockSkewSeconds: max(1, (int) config('till-health.clock_skew_seconds', 300)),
            syncFailingHours: max(1, (int) config('till-health.sync_failing_hours', 24)),
            syncStalledHours: max(1, (int) config('till-health.sync_stalled_hours', 24)),
            alertOfflineHours: max(1, (int) config('till-health.alert_offline_hours', 4)),
            minimumAppVersion: TillProfile::minimumAppVersion((string) config('licence.api.minimum_app_version', '0.1.0')),
            tradingStart: (string) ($hours['start'] ?? '08:00'),
            tradingEnd: (string) ($hours['end'] ?? '20:00'),
            timezone: is_string($zone = config('till-health.timezone')) ? $zone : null,
            refreshMinutes: max(1, (int) config('till-health.refresh_minutes', 5)),
        );
    }

    /**
     * For the screens ("Online = synced within 15 min").
     *
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
