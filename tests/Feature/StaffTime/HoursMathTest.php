<?php

use App\Domain\StaffTime\Data\WorkedShift;
use App\Domain\StaffTime\Support\ClockPairing;
use App\Domain\StaffTime\Support\HoursMath;
use Carbon\CarbonImmutable;

/*
 * Module 5.6: pairing clock events into shifts, hours, rounding, overtime and rota hours, including overnight shifts
 * and both clock changes (Europe/London: 29 Mar 2026 forward, 25 Oct 2026 back).
 */

/** A clock event at a London wall-clock time. */
function clockAt(string $type, string $london, string $user = 'U1', string $shop = 'SHOP-A'): array
{
    return ['id' => $type.$london.$user, 'userId' => $user, 'type' => $type, 'at' => CarbonImmutable::parse($london, 'Europe/London')->utc(), 'branchId' => $shop, 'registerId' => 'TILL-'.$shop];
}

function pairAt(array $events, string $nowLondon = '2026-12-31 12:00'): array
{
    return ClockPairing::pair($events, CarbonImmutable::parse($nowLondon, 'Europe/London')->utc());
}

test('a day shift with a break: worked is in to out less the break', function () {
    [$shift] = pairAt([clockAt('in', '2026-09-21 09:00'), clockAt('breakStart', '2026-09-21 12:00'), clockAt('breakEnd', '2026-09-21 12:30'), clockAt('out', '2026-09-21 17:15')]);

    expect($shift->status)->toBe(WorkedShift::COMPLETE)
        ->and($shift->breakMinutes())->toBe(30)
        ->and($shift->workedMinutes())->toBe(465)
        ->and($shift->day())->toBe('2026-09-21')
        ->and($shift->weekStart())->toBe('2026-09-21');
});

test('an overnight shift counts on the day it started, in its week', function () {
    [$shift] = pairAt([clockAt('in', '2026-09-27 22:00'), clockAt('out', '2026-09-28 06:00')]);

    expect($shift->workedMinutes())->toBe(480)
        ->and($shift->day())->toBe('2026-09-27')
        ->and($shift->weekStart())->toBe('2026-09-21');
});

test('overnight across the clock changes counts real time: 7 hours in March, 9 hours in October', function () {
    [$spring] = pairAt([clockAt('in', '2026-03-28 22:00'), clockAt('out', '2026-03-29 06:00')]);
    [$autumn] = pairAt([clockAt('in', '2026-10-24 22:00'), clockAt('out', '2026-10-25 06:00')]);

    expect($spring->workedMinutes())->toBe(420)
        ->and($autumn->workedMinutes())->toBe(540)
        ->and($autumn->day())->toBe('2026-10-24');
});

test('a missed clock-out: followed by another clock-in, or open longer than the limit; recent = on shift', function () {
    $shifts = pairAt([clockAt('in', '2026-09-21 09:00'), clockAt('in', '2026-09-22 09:00'), clockAt('out', '2026-09-22 17:00')]);

    expect(array_map(fn (WorkedShift $s) => $s->status, $shifts))->toBe([WorkedShift::MISSING_OUT, WorkedShift::COMPLETE])
        ->and($shifts[0]->workedMinutes())->toBe(0)
        ->and($shifts[1]->workedMinutes())->toBe(480);

    [$stale] = pairAt([clockAt('in', '2026-09-21 09:00')], '2026-09-22 01:01');
    [$recent] = pairAt([clockAt('in', '2026-09-21 09:00')], '2026-09-21 20:00');

    expect($stale->status)->toBe(WorkedShift::MISSING_OUT)
        ->and($recent->status)->toBe(WorkedShift::OPEN)
        ->and($recent->workedMinutes())->toBe(0);
});

test('a clock-out without a clock-in, and a break never ended, are flagged', function () {
    $shifts = pairAt([clockAt('out', '2026-09-21 08:00'), clockAt('in', '2026-09-21 09:00'), clockAt('breakStart', '2026-09-21 16:00'), clockAt('out', '2026-09-21 17:00')]);

    expect($shifts[0]->status)->toBe(WorkedShift::MISSING_IN)
        ->and($shifts[1]->flags)->toBe(['breakNotEnded'])
        ->and($shifts[1]->breakMinutes())->toBe(60)
        ->and($shifts[1]->workedMinutes())->toBe(420);
});

test('people are paired separately and same-second out then in closes the first shift', function () {
    $shifts = pairAt([
        clockAt('in', '2026-09-21 09:00', 'U1'), clockAt('in', '2026-09-21 10:00', 'U2'),
        clockAt('out', '2026-09-21 13:00', 'U1'), clockAt('in', '2026-09-21 13:00', 'U1'), clockAt('out', '2026-09-21 15:00', 'U1'),
        clockAt('out', '2026-09-21 18:00', 'U2'),
    ]);

    expect(array_map(fn (WorkedShift $s) => [$s->userId, $s->status, $s->workedMinutes()], $shifts))->toBe([
        ['U1', 'complete', 240], ['U2', 'complete', 480], ['U1', 'complete', 120],
    ]);
});

test('rounding to the nearest step, halves up', function () {
    expect(HoursMath::roundMinutes(472, 15))->toBe(465)
        ->and(HoursMath::roundMinutes(473, 15))->toBe(480)
        ->and(HoursMath::roundMinutes(472, 0))->toBe(472)
        ->and(HoursMath::roundMinutes(452, 5))->toBe(450)
        ->and(HoursMath::roundMinutes(455, 10))->toBe(460);
});

test('weekly overtime goes to the shifts that cross the threshold, per person and week', function () {
    $events = [];
    foreach (['21', '22', '23', '24', '25'] as $i => $day) {
        $shop = $i === 4 ? 'SHOP-B' : 'SHOP-A';
        $events[] = clockAt('in', "2026-09-$day 08:00", 'U1', $shop);
        $events[] = clockAt('out', "2026-09-$day 17:00", 'U1', $shop);
    }
    $events[] = clockAt('in', '2026-09-28 08:00', 'U1');
    $events[] = clockAt('out', '2026-09-28 20:00', 'U1');

    $shifts = pairAt($events);
    HoursMath::round($shifts, 0);
    HoursMath::overtime($shifts, 40 * 60);

    expect(array_map(fn (WorkedShift $s) => $s->overtimeMinutes, $shifts))->toBe([0, 0, 0, 0, 300, 0]);

    HoursMath::overtime($shifts, 30 * 60);
    expect(array_map(fn (WorkedShift $s) => $s->overtimeMinutes, $shifts))->toBe([0, 0, 0, 360, 540, 0]);

    HoursMath::overtime($shifts, null);
    expect(array_sum(array_map(fn (WorkedShift $s) => $s->overtimeMinutes, $shifts)))->toBe(0);
});

test('rota hours: day, overnight, both clock changes, and unreadable times', function () {
    expect(HoursMath::plannedMinutes('2026-09-21', '09:00', '17:00', 30))->toBe(450)
        ->and(HoursMath::plannedMinutes('2026-09-21', '22:00:00', '06:00:00', 0))->toBe(480)
        ->and(HoursMath::plannedMinutes('2026-03-28', '22:00', '06:00', 0))->toBe(420)
        ->and(HoursMath::plannedMinutes('2026-10-24', '22:00', '06:00', 0))->toBe(540)
        ->and(HoursMath::plannedMinutes('2026-09-21', '09:00:00.0000000', '09:00', 0))->toBe(1440)
        ->and(HoursMath::plannedMinutes('2026-09-21', 'soon', '17:00', 0))->toBeNull()
        ->and(HoursMath::clock('9:05:00'))->toBe('09:05');
});

test('hours and wage estimates are exact decimals', function () {
    expect(HoursMath::hours(450))->toBe('7.50')
        ->and(HoursMath::hours(20))->toBe('0.33')
        ->and(HoursMath::wage(450, '12.21'))->toBe('91.58')
        ->and(HoursMath::wage(450, null))->toBeNull()
        ->and(HoursMath::wage(450, '0.00'))->toBeNull();
});
