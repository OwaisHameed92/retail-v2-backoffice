<?php

namespace App\Domain\TillHealth\Queries;

use App\Domain\TillHealth\Enums\SyncState;
use App\Domain\TillHealth\Enums\TillState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Counts over the stored health rows (module 2.7): the admin dashboard tile, the System health row and the Till
 * health page's filter counts. Two aggregate queries whatever the number of tills.
 *
 * @phpstan-type Summary array{tills: int, online: int, stale: int, offline: int, notActivated: int, attention: int, oldVersion: int, sync: int, clockSkew: int, shops: int, shopsOnline: int, shopsSyncing: int, checkedAt: string|null}
 */
final class TillHealthSummary
{
    /**
     * @param  string|null  $companyId  One business (the list filtered to it), or all.
     * @return Summary
     */
    public static function compute(?string $companyId = null): array
    {
        $online = TillState::Online->value;
        $stale = TillState::Stale->value;
        $offline = TillState::Offline->value;
        $never = TillState::NotActivated->value;
        $problems = [SyncState::Failing->value, SyncState::Stalled->value];

        $tills = DB::table('till_health')
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->selectRaw('count(*) as tills')
            ->selectRaw('sum(case when state = ? then 1 else 0 end) as online', [$online])
            ->selectRaw('sum(case when state = ? then 1 else 0 end) as stale', [$stale])
            ->selectRaw('sum(case when state = ? then 1 else 0 end) as offline', [$offline])
            ->selectRaw('sum(case when state = ? then 1 else 0 end) as not_activated', [$never])
            ->selectRaw('sum(case when problem_count > 0 then 1 else 0 end) as attention')
            ->selectRaw('sum(case when app_outdated = 1 then 1 else 0 end) as old_version')
            ->selectRaw('sum(case when sync_state in (?, ?) then 1 else 0 end) as sync', $problems)
            ->selectRaw('sum(case when clock_skewed = 1 then 1 else 0 end) as clock_skew')
            ->selectRaw('max(checked_at) as checked_at')
            ->first();

        $shops = DB::table('branch_health')
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->selectRaw('count(*) as shops')
            ->selectRaw('sum(case when state = ? then 1 else 0 end) as online', [$online])
            ->selectRaw('sum(case when sync_state != ? then 1 else 0 end) as syncing', [SyncState::NotLinked->value])
            ->first();

        $int = fn (?object $row, string $key) => (int) ($row->{$key} ?? 0);
        $checked = $tills->checked_at ?? null;

        return [
            'tills' => $int($tills, 'tills'),
            'online' => $int($tills, 'online'),
            'stale' => $int($tills, 'stale'),
            'offline' => $int($tills, 'offline'),
            'notActivated' => $int($tills, 'not_activated'),
            'attention' => $int($tills, 'attention'),
            'oldVersion' => $int($tills, 'old_version'),
            'sync' => $int($tills, 'sync'),
            'clockSkew' => $int($tills, 'clock_skew'),
            'shops' => $int($shops, 'shops'),
            'shopsOnline' => $int($shops, 'online'),
            'shopsSyncing' => $int($shops, 'syncing'),
            'checkedAt' => $checked === null ? null : CarbonImmutable::parse((string) $checked, 'UTC')->toIso8601ZuluString(),
        ];
    }
}
