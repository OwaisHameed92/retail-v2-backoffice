<?php

namespace App\Domain\TillHealth\Enums;

use App\Domain\Licensing\Enums\LicenceAlertType;

/**
 * What can be wrong with a till (module 2.7): the admin list's filters and the alerts `till-health:refresh` raises
 * and clears on the till's licence (the existing licence alert mechanism).
 */
enum HealthProblem: string
{
    case Offline = 'offline';
    case OldVersion = 'oldVersion';
    case SyncFailing = 'syncFailing';
    case SyncStalled = 'syncStalled';
    case ClockSkew = 'clockSkew';

    public function label(): string
    {
        return match ($this) {
            self::Offline => 'Offline',
            self::OldVersion => 'Old version',
            self::SyncFailing => 'Sync failing',
            self::SyncStalled => 'Sync stalled',
            self::ClockSkew => 'Clock skew',
        };
    }

    public function alertType(): LicenceAlertType
    {
        return match ($this) {
            self::Offline => LicenceAlertType::TillOffline,
            self::OldVersion => LicenceAlertType::AppVersionOutdated,
            self::SyncFailing => LicenceAlertType::SyncFailing,
            self::SyncStalled => LicenceAlertType::SyncStalled,
            self::ClockSkew => LicenceAlertType::ClockSkew,
        };
    }
}
