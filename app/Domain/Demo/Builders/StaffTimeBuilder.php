<?php

namespace App\Domain\Demo\Builders;

use App\Domain\Demo\Support\DemoBusiness;
use App\Domain\Demo\Support\DemoPush;
use App\Domain\Demo\Support\DemoStaff;
use App\Domain\Reporting\Demo\DemoShop;
use Carbon\CarbonImmutable;
use Random\Randomizer;

/**
 * Each shop's rota (the history window and two weeks ahead, the last week not yet published), the clock-ins and
 * outs with breaks (some late, some staying on), weekly timesheet approvals with the overtime (over 8 hours a day) (the last
 * week still waiting), and training records (age-restricted sales, food hygiene, fire safety) with expiry dates,
 * one expired and one about to.
 */
final class StaffTimeBuilder
{
    /** Kind => [start, end, break minutes, ISO weekdays worked]. Cashier patterns by position (DemoShop::cashiers). */
    public const PATTERNS = [
        'manager' => ['09:00', '17:30', 30, [1, 2, 3, 4, 5]],
        'cashier0' => ['07:00', '15:00', 30, [1, 2, 4, 5, 6]],
        'cashier1' => ['12:00', '20:00', 30, [3, 4, 5, 6, 7]],
        'cashier2' => ['16:00', '22:00', 0, [1, 2, 3, 5]],
        'weekend' => ['09:00', '17:00', 30, [6, 7]],
    ];

    public function handle(DemoBusiness $b, DemoPush $push): void
    {
        foreach ($b->shops as ['shop' => $shop]) {
            $rng = $b->rng("staff-time|{$shop->branchId}");
            $weeks = [];

            foreach (DemoStaff::at($b, $shop) as $member) {
                $pattern = $this->pattern($shop, $member);

                if ($pattern === null) {
                    continue;
                }

                [$start, $end, $break, $days] = $pattern;

                for ($d = $b->history; $d >= -13; $d--) {
                    $date = CarbonImmutable::parse($b->date($d), 'UTC');

                    if (! in_array($date->dayOfWeekIso, $days, true)) {
                        continue;
                    }

                    $push->add($shop, 'RotaShift', $shop->id("rota|{$member['id']}|{$date->toDateString()}"), [
                        'userId' => $member['id'], 'shiftDate' => $date->toDateString(), 'startTime' => "{$start}:00", 'endTime' => "{$end}:00",
                        'breakMinutes' => $break, 'isPublished' => $d > -7, 'note' => $d === -3 && $member['kind'] === 'manager' ? 'Stock take evening' : '',
                        'branchId' => $shop->branchId,
                    ], $b->at(max($d, 0) + 10, 14), $b->at(max($d, 0) + 10, 14));

                    if ($d >= 0) {
                        $hours = $this->clock($b, $push, $shop, $rng, $member['id'], $d, $start, $end, $break);
                        $week = $date->startOfWeek()->toDateString();
                        $weeks[$week][$member['id']] ??= [0.0, 0.0];
                        $weeks[$week][$member['id']][0] += $hours;
                        $weeks[$week][$member['id']][1] += max(0, $hours - 8); // the till's rule: over 8 hours in a day
                    }
                }
            }

            $this->approvals($b, $push, $shop, $weeks);
            $this->training($b, $push, $shop);
        }
    }

    /**
     * @param  array<string, mixed>  $member
     * @return array{0: string, 1: string, 2: int, 3: list<int>}|null
     */
    private function pattern(DemoShop $shop, array $member): ?array
    {
        $index = array_search($member['id'], $shop->cashiers, true);

        return match ($member['kind']) {
            'manager', 'weekend' => self::PATTERNS[$member['kind']],
            'cashier' => $index === false ? null : self::PATTERNS["cashier{$index}"],
            default => null,
        };
    }

    /** Clock events of one worked day; returns the hours worked (less the break). */
    private function clock(DemoBusiness $b, DemoPush $push, DemoShop $shop, Randomizer $rng, string $userId, int $daysAgo, string $start, string $end, int $break): float
    {
        [$sh, $sm] = array_map('intval', explode(':', $start));
        [$eh, $em] = array_map('intval', explode(':', $end));
        $late = $rng->nextFloat() < 0.08 ? $rng->getInt(10, 25) : $rng->getInt(-8, 3);
        $stay = $rng->nextFloat() < 0.12 ? $rng->getInt(30, 75) : $rng->getInt(0, 12);
        $in = $b->at($daysAgo, $sh, $sm)->addMinutes($late);
        $out = $b->at($daysAgo, $eh % 24, $em)->addMinutes($stay);
        $register = $shop->registers[0]['id'];
        $events = [['in', $in, $late >= 10 ? 'Bus was late' : '']];

        if ($break > 0) {
            $breakAt = $in->addMinutes((int) (($out->getTimestamp() - $in->getTimestamp()) / 120));
            $events[] = ['breakStart', $breakAt, ''];
            $events[] = ['breakEnd', $breakAt->addMinutes($break + $rng->getInt(-2, 4)), ''];
        }

        $events[] = ['out', $out, $stay >= 30 ? 'Stayed on to help with the delivery' : ''];

        foreach ($events as [$type, $at, $note]) {
            if ($at > $b->now) {
                break;
            }

            $push->add($shop, 'ClockEvent', $shop->id("clock|{$userId}|{$at->toDateString()}|{$type}"), [
                'userId' => $userId, 'type' => $type, 'at' => DemoBusiness::iso($at), 'note' => $note, 'registerId' => $register, 'branchId' => $shop->branchId,
            ], $at);
        }

        return round((($out->getTimestamp() - $in->getTimestamp()) / 60 - $break) / 60, 2);
    }

    /**
     * Every finished week is approved by the manager (the manager's own by the owner), except the latest.
     *
     * @param  array<string, array<string, array{0: float, 1: float}>>  $weeks  week start => user => [hours, overtime]
     */
    private function approvals(DemoBusiness $b, DemoPush $push, DemoShop $shop, array $weeks): void
    {
        ksort($weeks);
        $thisWeek = CarbonImmutable::parse($b->today, 'UTC')->startOfWeek()->toDateString();
        $finished = array_values(array_filter(array_keys($weeks), fn (string $w) => $w < $thisWeek));
        array_pop($finished);

        foreach ($finished as $week) {
            $approvedAt = CarbonImmutable::parse($week, 'UTC')->addDays(8)->setTime(10, 15);

            foreach ($weeks[$week] as $userId => [$hours, $overtime]) {
                $push->add($shop, 'TimesheetApproval', $shop->id("timesheet|{$userId}|{$week}"), [
                    'userId' => $userId, 'weekStart' => $week, 'approvedByUserId' => $userId === DemoStaff::manager($shop) ? $b->id('user|owner') : DemoStaff::manager($shop),
                    'approvedAt' => DemoBusiness::iso($approvedAt), 'totalHours' => round($hours, 2), 'overtimeHours' => round($overtime, 2),
                    'branchId' => $shop->branchId,
                ], $approvedAt);
            }
        }
    }

    private function training(DemoBusiness $b, DemoPush $push, DemoShop $shop): void
    {
        $courses = [
            ['Challenge 25 and age-restricted sales', 300, 12, 'Trading Standards e-learning'],
            ['Food hygiene level 2', 500, 36, 'Highfield online'],
            ['Fire safety awareness', 200, 12, 'Shop manager'],
            ['Manual handling', 400, null, 'Shop manager'],
        ];

        foreach (DemoStaff::at($b, $shop) as $i => $member) {
            foreach ($courses as $c => [$topic, $ago, $months, $trainer]) {
                if ($member['kind'] === 'owner' && $c > 0) {
                    continue;
                }

                // The first cashier's age-restricted sales training has run out; the weekend part-timer's runs out this month.
                $daysAgo = $ago + $i * 17 + ($c === 0 && $member['id'] === $shop->cashiers[0] ? 90 : 0) + ($c === 0 && $member['kind'] === 'weekend' ? 340 - $ago - $i * 17 : 0);
                $on = CarbonImmutable::parse($b->today, 'UTC')->subDays($daysAgo);
                $push->add($shop, 'TrainingRecord', $shop->id("training|{$member['id']}|{$c}"), [
                    'userId' => $member['id'], 'topic' => $topic, 'trainedOn' => $on->toDateString(),
                    'expiresOn' => $months === null ? null : $on->addMonths($months)->toDateString(), 'trainerName' => $trainer,
                    'notes' => $c === 0 ? 'Refusals log and ID checks covered' : '', 'branchId' => $shop->branchId,
                ], $on->setTime(16, 0));
            }
        }
    }
}
