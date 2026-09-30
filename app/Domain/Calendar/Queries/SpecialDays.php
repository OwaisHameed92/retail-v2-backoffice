<?php

namespace App\Domain\Calendar\Queries;

use App\Domain\Calendar\Data\CalendarFilters;
use App\Domain\Cash\Support\CashLookup;
use App\Domain\TillData\Models\BranchHoursOverride;

/**
 * Special days (module 5.9): the tills' `BranchHoursOverride` rows (bank holidays, closures, event hours). They are
 * branch-owned (ownership.json), so the portal only lists them: a shop sets its special days on its till. Upcoming
 * ones first by date; past ones newest first. Till health uses them for trading time (ShopHours).
 */
final class SpecialDays
{
    public const LIMIT = 200;

    /**
     * @return array<string, mixed>
     */
    public static function for(CalendarFilters $filters): array
    {
        $query = BranchHoursOverride::query()
            ->when($filters->shop !== null, fn ($q) => $q->where('branch_id', $filters->shop))
            ->when($filters->when === 'upcoming', fn ($q) => $q->where('date', '>=', $filters->today)->orderBy('date'))
            ->when($filters->when === 'past', fn ($q) => $q->where('date', '<', $filters->today)->orderByDesc('date'))
            ->when($filters->when === 'all', fn ($q) => $q->orderByDesc('date'));
        $total = (clone $query)->count();
        $rows = $query->orderBy('branch_id')->limit(self::LIMIT)->get();
        $shops = CashLookup::shops($rows->pluck('branch_id'));

        return [
            'days' => $rows->map(fn (BranchHoursOverride $o) => self::row($o, $shops))->values()->all(),
            'total' => $total,
            'limit' => self::LIMIT,
        ];
    }

    /**
     * @param  array<string, string>|null  $shops  id => name (null: the caller shows one shop)
     * @return array<string, mixed>
     */
    public static function row(BranchHoursOverride $o, ?array $shops): array
    {
        return [
            'id' => (string) $o->id,
            'date' => $o->date->format('Y-m-d'),
            'shop' => $shops === null ? null : CashLookup::name($shops, $o->branch_id),
            'event' => (string) $o->seasonal_event_name !== '' ? (string) $o->seasonal_event_name : null,
            'closed' => (bool) $o->is_closed,
            'opens' => ShopHours::time($o->general_opens_at),
            'closes' => ShopHours::time($o->general_closes_at),
            'licensedOpens' => ShopHours::time($o->licensed_opens_at),
            'licensedCloses' => ShopHours::time($o->licensed_closes_at),
            'notes' => (string) $o->notes !== '' ? (string) $o->notes : null,
        ];
    }
}
