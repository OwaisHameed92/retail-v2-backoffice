<?php

namespace App\Domain\Calendar\Queries;

use App\Domain\Calendar\Data\CalendarFilters;
use App\Domain\Cash\Support\CashLookup;
use App\Domain\TillData\Models\SeasonalEvent;
use App\Domain\TillData\Models\SeasonalEventUplift;

/**
 * Seasonal events (module 5.9): the tills' `SeasonalEvent` rows (Christmas, Ramadan, Easter, bank and school
 * holidays, local events). They are branch-owned (ownership.json): each shop's till keeps its own list and its learned
 * department uplifts, so the portal lists them and compares each with last year's sales (EventComparison).
 * Upcoming = not ended yet (on now first), soonest first; past = newest first.
 */
final class SeasonalEventList
{
    public const LIMIT = 200;

    /**
     * @return array<string, mixed>
     */
    public static function for(CalendarFilters $filters): array
    {
        $query = SeasonalEvent::query()
            ->when($filters->shop !== null, fn ($q) => $q->where('branch_id', $filters->shop))
            ->when($filters->when === 'upcoming', fn ($q) => $q->where('ends_on', '>=', $filters->today)->orderBy('starts_on'))
            ->when($filters->when === 'past', fn ($q) => $q->where('ends_on', '<', $filters->today)->orderByDesc('starts_on'))
            ->when($filters->when === 'all', fn ($q) => $q->orderByDesc('starts_on'));
        $total = (clone $query)->count();
        $events = $query->orderBy('name')->limit(self::LIMIT)->get();
        $shops = CashLookup::shops($events->pluck('branch_id'));
        $uplifts = SeasonalEventUplift::query()->whereIn('seasonal_event_id', $events->pluck('id'))
            ->selectRaw('seasonal_event_id, count(*) as n')->groupBy('seasonal_event_id')->pluck('n', 'seasonal_event_id');

        return [
            'events' => $events->map(fn (SeasonalEvent $e) => [
                ...self::row($e, $filters->today),
                'shop' => CashLookup::name($shops, $e->branch_id),
                'uplifts' => (int) ($uplifts[$e->id] ?? 0),
            ])->values()->all(),
            'total' => $total,
            'limit' => self::LIMIT,
        ];
    }

    /**
     * @return array{id: string, name: string, kind: string|null, startsOn: string, endsOn: string, days: int, nation: string|null, notes: string|null, isActive: bool, status: string}
     */
    public static function row(SeasonalEvent $e, string $today): array
    {
        $starts = $e->starts_on->format('Y-m-d');
        $ends = $e->ends_on->format('Y-m-d');

        return [
            'id' => (string) $e->id,
            'name' => (string) $e->name,
            'kind' => $e->kind?->value,
            'startsOn' => $starts,
            'endsOn' => $ends,
            'days' => (int) $e->starts_on->diffInDays($e->ends_on) + 1,
            'nation' => (string) $e->nation !== '' ? (string) $e->nation : null,
            'notes' => (string) $e->notes !== '' ? (string) $e->notes : null,
            'isActive' => (bool) $e->is_active,
            'status' => match (true) {
                $ends < $today => 'past',
                $starts <= $today => 'onNow',
                default => 'upcoming',
            },
        ];
    }
}
