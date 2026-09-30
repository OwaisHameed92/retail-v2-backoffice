<?php

namespace App\Domain\StaffTime\Queries;

use App\Domain\Cash\Support\CashLookup;
use App\Domain\StaffTime\Data\TimeFilters;
use App\Domain\StaffTime\Data\WorkedShift;
use App\Domain\StaffTime\Support\ClockPairing;
use App\Domain\StaffTime\Support\TimeLookup;
use Carbon\CarbonImmutable;

/**
 * The clock events screen (module 5.6): each person's shifts built from the tills' clock in / out / break events,
 * newest first, with missing clock-outs, missing clock-ins, breaks never ended and shifts over the person's maximum
 * shift hours (User.maxShiftHours) flagged. Read only: clock events are the tills' (branch-owned).
 */
final class ClockList
{
    /**
     * @return array<string, mixed>
     */
    public static function for(TimeFilters $filters, int $page, int $perPage, ?CarbonImmutable $now = null): array
    {
        $shifts = array_values(array_filter(
            TimeSource::shifts($filters, $now),
            fn (WorkedShift $s) => $filters->includes($s->day()) && ($filters->till === null || $s->registerId === $filters->till),
        ));

        $pay = TimeLookup::pay(array_map(fn (WorkedShift $s) => $s->userId, $shifts));
        foreach ($shifts as $shift) {
            $max = $pay[$shift->userId]['maxShiftMinutes'] ?? null;
            if ($max !== null && $shift->workedMinutes() > $max) {
                $shift->flags[] = 'overMaxShift';
            }
        }

        $problems = array_values(array_filter($shifts, fn (WorkedShift $s) => ! $s->isComplete() || $s->flags !== []));
        $shown = array_reverse($filters->problems ? $problems : $shifts);
        $rota = self::rotaByPersonDay($filters);
        $people = CashLookup::staff(array_map(fn (WorkedShift $s) => $s->userId, $shown));
        $shops = CashLookup::shops(array_map(fn (WorkedShift $s) => $s->branchId, $shown));
        $tills = CashLookup::tills(array_map(fn (WorkedShift $s) => $s->registerId, $shown));
        $complete = array_filter($shifts, fn (WorkedShift $s) => $s->isComplete());

        $page = TimeLookup::page($shown, $page, $perPage);
        $page['data'] = array_map(fn (WorkedShift $s) => [
            'id' => $s->id ?? $s->userId.'-'.$s->startsAt()->getTimestamp(),
            'personId' => $s->userId,
            'person' => $people[$s->userId] ?? 'Unknown',
            'shop' => CashLookup::name($shops, $s->branchId),
            'till' => CashLookup::name($tills, $s->registerId),
            'day' => $s->day(),
            'clockIn' => CashLookup::iso($s->clockIn),
            'clockOut' => CashLookup::iso($s->clockOut),
            'breaks' => array_map(fn (array $b) => ['start' => CashLookup::iso($b['start']), 'end' => CashLookup::iso($b['end'])], $s->breaks),
            'breakMinutes' => $s->breakMinutes(),
            'workedMinutes' => $s->workedMinutes(),
            'paidMinutes' => $s->paidMinutes,
            'status' => $s->status,
            'flags' => $s->flags,
            'planned' => $rota[$s->userId.'|'.$s->day()] ?? null,
        ], $page['data']);

        return [
            'shifts' => $page,
            'summary' => [
                'shifts' => count($complete),
                'workedMinutes' => array_sum(array_map(fn (WorkedShift $s) => $s->workedMinutes(), $complete)),
                'paidMinutes' => array_sum(array_map(fn (WorkedShift $s) => $s->paidMinutes, $complete)),
                'missing' => count(array_filter($shifts, fn (WorkedShift $s) => in_array($s->status, [WorkedShift::MISSING_OUT, WorkedShift::MISSING_IN], true))),
                'onShift' => count(array_filter($shifts, fn (WorkedShift $s) => $s->status === WorkedShift::OPEN)),
                'problems' => count($problems),
                'openLimitHours' => ClockPairing::OPEN_LIMIT_HOURS,
            ],
        ];
    }

    /**
     * "09:00–17:00" of each person's rota for a day (several shifts joined with ", ").
     *
     * @return array<string, string>
     */
    private static function rotaByPersonDay(TimeFilters $filters): array
    {
        $out = [];

        foreach (TimeSource::rota($filters, $filters->from, $filters->to) as $r) {
            $key = $r['userId'].'|'.$r['day'];
            $text = ($r['start'] ?? '?').'–'.($r['end'] ?? '?');
            $out[$key] = isset($out[$key]) ? $out[$key].', '.$text : $text;
        }

        return $out;
    }
}
