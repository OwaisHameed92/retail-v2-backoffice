<?php

namespace App\Domain\Calendar\Queries;

use App\Domain\Calendar\Models\ShopOpeningHour;
use App\Domain\Calendar\Support\WeeklyHours;
use App\Domain\TillData\Models\BranchHoursOverride;
use Carbon\CarbonImmutable;

/**
 * Shops' opening hours (module 5.9): the week kept on the portal plus the till's special days
 * (`BranchHoursOverride`: closed, or its own general hours). A shop with neither is left out, so the caller keeps
 * its default. Reads across companies by shop id (Till health's command runs for every business).
 */
final class ShopHours
{
    /**
     * @param  list<string>  $branchIds
     * @return array<string, WeeklyHours> branch id => hours, only shops with hours or special days
     */
    public static function forBranches(array $branchIds, CarbonImmutable $from, CarbonImmutable $to, WeeklyHours $default): array
    {
        $branchIds = array_values(array_unique(array_filter($branchIds)));

        if ($branchIds === []) {
            return [];
        }

        $weeks = [];
        foreach (ShopOpeningHour::withoutCompanyScope()->whereIn('branch_id', $branchIds)->get() as $row) {
            $weeks[$row->branch_id] ??= $default->days;
            $weeks[$row->branch_id][$row->weekday] = self::hours($row->is_closed, $row->opens_at, $row->closes_at);
        }

        $special = [];
        $overrides = BranchHoursOverride::withoutCompanyScope()->whereIn('branch_id', $branchIds)
            ->whereBetween('date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->get(['id', 'branch_id', 'date', 'is_closed', 'general_opens_at', 'general_closes_at']);

        foreach ($overrides as $row) {
            $opens = self::time($row->general_opens_at);
            $closes = self::time($row->general_closes_at);

            if (! $row->is_closed && ($opens === null || $closes === null)) {
                continue; // Only the licensed (alcohol) hours changed: trading follows the week.
            }

            $special[(string) $row->branch_id][$row->date->format('Y-m-d')] = self::hours((bool) $row->is_closed, $opens, $closes);
        }

        $out = [];
        foreach (array_unique([...array_keys($weeks), ...array_keys($special)]) as $branchId) {
            $out[(string) $branchId] = new WeeklyHours($weeks[$branchId] ?? $default->days, $special[$branchId] ?? []);
        }

        return $out;
    }

    /** "HH:MM" from a till time ("07:00:00") or null. */
    public static function time(mixed $value): ?string
    {
        $text = is_string($value) ? substr(trim($value), 0, 5) : '';

        return preg_match(WeeklyHours::TIME, $text) === 1 ? $text : null;
    }

    /**
     * @return array{opens: string, closes: string}|null
     */
    private static function hours(bool $closed, ?string $opens, ?string $closes): ?array
    {
        return $closed || $opens === null || $closes === null ? null : ['opens' => $opens, 'closes' => $closes];
    }
}
