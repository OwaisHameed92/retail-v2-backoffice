<?php

namespace App\Domain\Anomalies\Support;

use App\Domain\Calendar\Queries\ShopHours;
use App\Domain\Calendar\Support\WeeklyHours;
use App\Domain\Reporting\Support\TradingDay;
use Carbon\CarbonImmutable;

/**
 * Opening hours as the detectors need them (module 6.6), from the shops' hours (module 5.9) and the till's special
 * days. A shop with no hours set is unknown (null): the detectors then rely on the sales baseline alone.
 */
final class ShopTimes
{
    /**
     * @param  list<string>  $branchIds
     * @return array<string, WeeklyHours> only shops with hours or special days
     */
    public static function for(array $branchIds, string $from, string $to): array
    {
        $tz = TradingDay::timezone();

        return ShopHours::forBranches(
            $branchIds,
            CarbonImmutable::parse($from, $tz)->subDay(),
            CarbonImmutable::parse($to, $tz)->addDay(),
            WeeklyHours::everyDay('00:00', '00:00'),
        );
    }

    /**
     * Whether a UTC instant is inside the shop's hours, give or take `$graceMinutes`: the window of its London day,
     * or the day before's when that one runs past midnight.
     */
    public static function inHours(WeeklyHours $hours, CarbonImmutable $utc, int $graceMinutes = 0): bool
    {
        $tz = TradingDay::timezone();
        $local = $utc->setTimezone($tz);

        foreach ([$local->startOfDay(), $local->startOfDay()->subDay()] as $day) {
            $window = $hours->window($day, $tz->getName());

            if ($window !== null && $utc->greaterThanOrEqualTo($window[0]->subMinutes($graceMinutes)) && $utc->lessThan($window[1]->addMinutes($graceMinutes))) {
                return true;
            }
        }

        return false;
    }

    /** Whether the whole of [from, to) is inside the shop's hours (no grace). */
    public static function openThroughout(WeeklyHours $hours, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return self::inHours($hours, $from) && self::inHours($hours, $to->subSecond());
    }

    /** Closed all day on a London date (a closed weekday or special day). */
    public static function closedOn(WeeklyHours $hours, string $day): bool
    {
        return $hours->forDate(CarbonImmutable::parse($day, TradingDay::timezone())) === null;
    }
}
