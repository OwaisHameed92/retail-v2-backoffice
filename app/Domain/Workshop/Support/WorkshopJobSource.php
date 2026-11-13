<?php

namespace App\Domain\Workshop\Support;

use App\Domain\TillData\Enums\SettingScope;
use App\Domain\TillData\Models\TillSetting;
use App\Domain\Workshop\Models\WorkshopConnection;

/**
 * The shop's branch setting `workshop.job_source` (`connector` | `direct`, default connector), set on the till's
 * Workshop integration card and pushed up like the other `workshop.*` keys (till team reply 2026-11-13, §5.0). While
 * the connector is connected, `direct` means the till makes new jobs from its own BikeBook key and skips a new
 * `WorkshopOrder` row of ours without telling anyone, so the portal must not accept a new Send to POS for that shop.
 */
final class WorkshopJobSource
{
    public const KEY = 'workshop.job_source';

    public static function isDirect(WorkshopConnection $connection): bool
    {
        $value = TillSetting::withoutCompanyScope()->where('company_id', $connection->company_id)
            ->where('scope', SettingScope::Branch->value)->where('scope_id', $connection->branch_id)
            ->where('setting_key', self::KEY)->value('value');

        return strtolower(trim((string) $value, " \t\n\r\0\x0B\"")) === 'direct';
    }
}
