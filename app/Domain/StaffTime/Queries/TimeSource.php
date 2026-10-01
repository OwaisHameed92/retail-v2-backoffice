<?php

namespace App\Domain\StaffTime\Queries;

use App\Domain\Reporting\Support\TradingDay;
use App\Domain\StaffTime\Data\TimeFilters;
use App\Domain\StaffTime\Data\WorkedShift;
use App\Domain\StaffTime\Support\ClockPairing;
use App\Domain\StaffTime\Support\HoursMath;
use App\Domain\TillData\Models\ClockEvent;
use App\Domain\TillData\Models\RotaShift;
use Carbon\CarbonImmutable;

/**
 * Reads the tills' clock events and rota rows for module 5.6, in the current company's scope (BelongsToCompany) and
 * the filters' shop (a one-shop user is pinned there) and person.
 *
 * Shifts are built over whole London weeks (weekly rows need the full week), with a day either side so an overnight
 * shift pairs across the edge; callers keep the days they show with `$filters->includes($shift->day())`.
 */
final class TimeSource
{
    /**
     * @return list<WorkedShift> rounded and with overtime set, in the filters' whole weeks
     */
    public static function shifts(TimeFilters $filters, ?CarbonImmutable $now = null): array
    {
        [$monday, $sunday] = $filters->weeks();
        $start = TradingDay::window($monday)[0]->subDay();
        $end = TradingDay::window($sunday)[1]->addDay();

        $events = ClockEvent::query()
            ->when($filters->shop !== null, fn ($q) => $q->where('branch_id', $filters->shop))
            ->when($filters->person !== null, fn ($q) => $q->where('user_id', $filters->person))
            ->whereNotNull('user_id')->whereNotNull('at')
            ->where('at', '>=', $start->format('Y-m-d H:i:s'))->where('at', '<', $end->format('Y-m-d H:i:s'))
            ->orderBy('at')
            ->get(['id', 'user_id', 'type', 'at', 'branch_id', 'register_id'])
            ->filter(fn (ClockEvent $e) => $e->type !== null)
            ->map(fn (ClockEvent $e) => [
                'id' => (string) $e->id, 'userId' => (string) $e->user_id, 'type' => $e->type->value, 'at' => $e->at,
                'branchId' => $e->branch_id, 'registerId' => $e->register_id,
            ]);

        $shifts = array_values(array_filter(
            ClockPairing::pair($events, $now ?? CarbonImmutable::now()),
            fn (WorkedShift $s) => $s->day() >= $monday && $s->day() <= $sunday,
        ));

        HoursMath::round($shifts, $filters->rounding);
        HoursMath::overtime($shifts);

        return $shifts;
    }

    /**
     * Rota rows of the London days $from to $to (inclusive).
     *
     * @return list<array{id: string, userId: string, branchId: ?string, day: string, start: ?string, end: ?string, breakMinutes: int, plannedMinutes: int, readable: bool, isPublished: bool, note: string}>
     */
    public static function rota(TimeFilters $filters, string $from, string $to): array
    {
        return RotaShift::query()
            ->when($filters->shop !== null, fn ($q) => $q->where('branch_id', $filters->shop))
            ->when($filters->person !== null, fn ($q) => $q->where('user_id', $filters->person))
            ->whereNotNull('user_id')
            ->where('shift_date', '>=', $from)->where('shift_date', '<=', $to)
            ->orderBy('shift_date')->orderBy('start_time')
            ->get(['id', 'user_id', 'branch_id', 'shift_date', 'start_time', 'end_time', 'break_minutes', 'is_published', 'note'])
            ->map(function (RotaShift $r) {
                $day = $r->shift_date->toDateString();
                $planned = HoursMath::plannedMinutes($day, $r->start_time, $r->end_time, (int) $r->break_minutes);

                return [
                    'id' => (string) $r->id, 'userId' => (string) $r->user_id, 'branchId' => $r->branch_id, 'day' => $day,
                    'start' => HoursMath::clock($r->start_time), 'end' => HoursMath::clock($r->end_time),
                    'breakMinutes' => max(0, (int) $r->break_minutes), 'plannedMinutes' => $planned ?? 0, 'readable' => $planned !== null,
                    'isPublished' => (bool) $r->is_published, 'note' => (string) $r->note,
                ];
            })->values()->all();
    }
}
