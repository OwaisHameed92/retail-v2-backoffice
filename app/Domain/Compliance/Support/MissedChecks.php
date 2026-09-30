<?php

namespace App\Domain\Compliance\Support;

use App\Domain\Reporting\Support\TradingDay;
use App\Domain\TillData\Enums\DiaryCheckDefinitionSchedule;
use App\Domain\TillData\Models\DiaryCheckDefinition;
use App\Domain\TillData\Models\DiaryCheckRecord;
use App\Domain\TillData\Models\Shift;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Which diary checks were missed (module 5.7). Each active definition is due once per period of its schedule:
 * `daily` = each London day, `weekly` = each Monday-to-Sunday London week, `perShift` = each till shift of its shop.
 * A period is **done** when the shop recorded the check (pass or fail) inside it, **missed** when it ended without
 * one, **due** while it is still running. Only periods from the day the definition was made count, and the shop's
 * opening days are not known yet (5.9), so every day counts.
 */
final class MissedChecks
{
    /**
     * The periods of one schedule that overlap the London days [from, to], never later than now.
     *
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable|null}>  $shifts  [opened, closed] of the shop's shifts
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}>
     */
    public static function periods(?DiaryCheckDefinitionSchedule $schedule, string $from, string $to, array $shifts, CarbonImmutable $now): array
    {
        $today = TradingDay::today($now)->format('Y-m-d');
        $to = min($to, $today);

        if ($from > $to) {
            return [];
        }

        return match ($schedule) {
            DiaryCheckDefinitionSchedule::Daily => array_map(function (string $day) {
                [$start, $end] = TradingDay::window($day);

                return ['start' => $start, 'end' => $end, 'label' => $day];
            }, TradingDay::range($from, $to)),
            DiaryCheckDefinitionSchedule::Weekly => self::weeks($from, $to),
            DiaryCheckDefinitionSchedule::PerShift => array_values(array_filter(array_map(
                fn (array $s) => ['start' => $s[0], 'end' => $s[1] ?? $now->addSecond(), 'label' => 'shift:'.$s[0]->format('Y-m-d\TH:i:s\Z')],
                $shifts,
            ), fn (array $p) => $p['start']->lt(TradingDay::window($to)[1]) && $p['start']->gte(TradingDay::window($from)[0]))),
            default => [],
        };
    }

    /**
     * Done, missed or due for each period, given when the check was recorded (UTC instants, any order).
     *
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}>  $periods
     * @param  list<CarbonImmutable>  $recorded
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, label: string, state: 'done'|'missed'|'due'}>
     */
    public static function evaluate(array $periods, array $recorded, CarbonImmutable $now): array
    {
        return array_map(function (array $p) use ($recorded, $now) {
            $done = false;

            foreach ($recorded as $at) {
                if ($at->gte($p['start']) && $at->lt($p['end'])) {
                    $done = true;
                    break;
                }
            }

            return [...$p, 'state' => $done ? 'done' : ($p['end']->lte($now) ? 'missed' : 'due')];
        }, $periods);
    }

    /**
     * Every active definition of the given ones with its evaluated periods in the London days [from, to].
     *
     * @param  Collection<int, DiaryCheckDefinition>  $definitions
     * @return array<string, list<array{start: CarbonImmutable, end: CarbonImmutable, label: string, state: 'done'|'missed'|'due'}>>
     */
    public static function forDefinitions(Collection $definitions, string $from, string $to, CarbonImmutable $now): array
    {
        $active = $definitions->filter(fn (DiaryCheckDefinition $d) => $d->is_active)->values();

        if ($active->isEmpty()) {
            return [];
        }

        // Records and shifts from the Monday before `from` (a week may start earlier) to the end of `to`.
        $start = TradingDay::window(CarbonImmutable::parse($from)->startOfWeek(CarbonInterface::MONDAY)->format('Y-m-d'))[0];
        $end = TradingDay::window($to)[1];
        $recorded = DiaryCheckRecord::query()->whereIn('diary_check_definition_id', $active->pluck('id')->all())
            ->where('recorded_at', '>=', $start->format('Y-m-d H:i:s'))->where('recorded_at', '<', $end->addWeek()->format('Y-m-d H:i:s'))
            ->get(['diary_check_definition_id', 'recorded_at'])->groupBy('diary_check_definition_id');
        $perShift = $active->filter(fn (DiaryCheckDefinition $d) => $d->schedule === DiaryCheckDefinitionSchedule::PerShift);
        $shifts = $perShift->isEmpty() ? collect() : Shift::query()->whereIn('branch_id', $perShift->pluck('branch_id')->unique()->all())
            ->where('opened_at', '>=', $start->format('Y-m-d H:i:s'))->where('opened_at', '<', $end->format('Y-m-d H:i:s'))
            ->orderBy('opened_at')->get(['branch_id', 'opened_at', 'closed_at'])->groupBy('branch_id');

        $out = [];

        foreach ($active as $d) {
            $made = $d->created_at !== null ? TradingDay::of($d->created_at)[0] : $from;
            $branchShifts = ($shifts[$d->branch_id] ?? collect())->map(fn (Shift $s) => [$s->opened_at, $s->closed_at])->values()->all();
            $times = ($recorded[$d->id] ?? collect())->map(fn (DiaryCheckRecord $r) => $r->recorded_at)->values()->all();
            $out[$d->id] = self::evaluate(self::periods($d->schedule, max($from, $made), $to, $branchShifts, $now), $times, $now);
        }

        return $out;
    }

    /**
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}>
     */
    private static function weeks(string $from, string $to): array
    {
        $weeks = [];

        for ($monday = CarbonImmutable::parse($from, 'UTC')->startOfWeek(CarbonInterface::MONDAY); $monday->format('Y-m-d') <= $to; $monday = $monday->addWeek()) {
            $day = $monday->format('Y-m-d');
            $weeks[] = ['start' => TradingDay::window($day)[0], 'end' => TradingDay::window($monday->addWeek()->format('Y-m-d'))[0], 'label' => 'week:'.$day];
        }

        return $weeks;
    }
}
