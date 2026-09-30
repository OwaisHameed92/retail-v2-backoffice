<?php

namespace App\Domain\StaffTime\Support;

use App\Domain\StaffTime\Data\WorkedShift;
use Carbon\CarbonImmutable;

/**
 * Turns the tills' clock events into shifts (module 5.6), one person at a time in time order:
 *
 * - `in` starts a shift. An `in` while a shift is open ends that shift as `missingOut` (never clocked out);
 * - `breakStart` / `breakEnd` open and close a break inside a shift (a second start or a lone end is ignored);
 * - `out` ends the shift; a break still open ends with it (flag `breakNotEnded`). An `out` with no shift open is a
 *   `missingIn` shift;
 * - a shift still open at the end is `open` (on shift now) until it is older than OPEN_LIMIT_HOURS, then `missingOut`.
 */
final class ClockPairing
{
    /** A shift clocked in longer ago than this, with no clock-out, is a missing clock-out. */
    public const OPEN_LIMIT_HOURS = 16;

    /**
     * @param  iterable<array{id?: string, userId: string, type: string, at: CarbonImmutable, branchId: ?string, registerId: ?string}>  $events
     * @return list<WorkedShift>
     */
    public static function pair(iterable $events, CarbonImmutable $now): array
    {
        $byUser = [];

        foreach ($events as $event) {
            $byUser[$event['userId']][] = $event;
        }

        $shifts = [];

        foreach ($byUser as $userEvents) {
            usort($userEvents, fn (array $a, array $b) => [$a['at']->getTimestamp(), self::order($a['type'])] <=> [$b['at']->getTimestamp(), self::order($b['type'])]);
            array_push($shifts, ...self::forPerson($userEvents, $now));
        }

        usort($shifts, fn (WorkedShift $a, WorkedShift $b) => [$a->startsAt()->getTimestamp(), $a->userId] <=> [$b->startsAt()->getTimestamp(), $b->userId]);

        return $shifts;
    }

    /**
     * @param  list<array{id?: string, userId: string, type: string, at: CarbonImmutable, branchId: ?string, registerId: ?string}>  $events
     * @return list<WorkedShift>
     */
    private static function forPerson(array $events, CarbonImmutable $now): array
    {
        $shifts = [];
        $open = null;

        foreach ($events as $event) {
            switch ($event['type']) {
                case 'in':
                    if ($open !== null) {
                        $open->status = WorkedShift::MISSING_OUT;
                        $shifts[] = $open;
                    }
                    $open = new WorkedShift($event['userId'], $event['branchId'], $event['registerId'], $event['at'], null, WorkedShift::OPEN, id: $event['id'] ?? null);
                    break;
                case 'breakStart':
                    if ($open !== null && ! self::onBreak($open)) {
                        $open->breaks[] = ['start' => $event['at'], 'end' => null];
                    }
                    break;
                case 'breakEnd':
                    if ($open !== null && self::onBreak($open)) {
                        $open->breaks[array_key_last($open->breaks)]['end'] = $event['at'];
                    }
                    break;
                case 'out':
                    if ($open === null) {
                        $shifts[] = new WorkedShift($event['userId'], $event['branchId'], $event['registerId'], null, $event['at'], WorkedShift::MISSING_IN, id: $event['id'] ?? null);
                        break;
                    }
                    if (self::onBreak($open)) {
                        $open->flags[] = 'breakNotEnded';
                    }
                    $open->clockOut = $event['at'];
                    $open->status = WorkedShift::COMPLETE;
                    $shifts[] = $open;
                    $open = null;
                    break;
            }
        }

        if ($open !== null) {
            $open->status = $open->startsAt()->addHours(self::OPEN_LIMIT_HOURS)->lessThan($now) ? WorkedShift::MISSING_OUT : WorkedShift::OPEN;
            $shifts[] = $open;
        }

        return $shifts;
    }

    private static function onBreak(WorkedShift $shift): bool
    {
        $last = $shift->breaks === [] ? null : $shift->breaks[array_key_last($shift->breaks)];

        return $last !== null && $last['end'] === null;
    }

    /** Same-second events: what ends comes before what starts (break end, out, in, break start). */
    private static function order(string $type): int
    {
        return match ($type) {
            'breakEnd' => 0,
            'out' => 1,
            'in' => 2,
            default => 3,
        };
    }
}
