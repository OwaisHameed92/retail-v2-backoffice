<?php

namespace App\Domain\Anomalies\Detectors;

use App\Domain\Anomalies\Data\AnomalyFinding;
use App\Domain\Anomalies\Data\DetectionWindow;
use App\Domain\Anomalies\Enums\AnomalyKind;
use App\Domain\Anomalies\Enums\AnomalySeverity;
use App\Domain\Anomalies\Support\AnomalyLinks as L;
use App\Domain\Anomalies\Support\Fmt;
use App\Domain\Anomalies\Support\ShopTimes;
use App\Domain\Calendar\Support\WeeklyHours;
use App\Domain\Reporting\Build\SaleFacts;
use App\Domain\Reporting\Support\TradingDay;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Trading outside opening hours (module 6.6, hourly for today and daily for yesterday): completed sales more than
 * {@see self::GRACE_MINUTES} minutes before opening or after closing (module 5.9 hours and the till's special days;
 * shops without hours are skipped), or on a closed day. At least two such sales and three, or £20. Quiet when the
 * shop traded outside its hours on {@see self::USUAL_SHARE} or more of its last 28 trading days: its hours are then
 * probably out of date rather than the day unusual.
 */
final class OutOfHours implements Detector
{
    public const GRACE_MINUTES = 15;

    public const USUAL_SHARE = 0.4;

    public function runsIn(string $mode): bool
    {
        return true;
    }

    public function detect(DetectionWindow $window): array
    {
        $from = CarbonImmutable::parse($window->day, 'UTC')->subDays(28)->toDateString();
        $hours = ShopTimes::for(array_keys($window->shops), $from, $window->day);
        $out = [];

        foreach ($hours as $branchId => $week) {
            $shop = $window->shops[$branchId] ?? null;

            if ($shop !== null && ! $this->usuallyOutside($window, $branchId, $week, $from)) {
                $finding = $this->day($window, $branchId, $shop, $week);
                $out = $finding !== null ? [...$out, $finding] : $out;
            }
        }

        return $out;
    }

    private function day(DetectionWindow $w, string $branchId, string $shop, WeeklyHours $week): ?AnomalyFinding
    {
        $sales = $this->sales($w->companyId, $branchId)->where('s.trading_day', $w->day)->orderBy('s.completed_at')
            ->get(['s.completed_at', 's.total']);
        $outside = [];
        $total = '0.00';

        foreach ($sales as $s) {
            $at = CarbonImmutable::parse((string) $s->completed_at, 'UTC');

            if (! ShopTimes::inHours($week, $at, self::GRACE_MINUTES)) {
                $outside[] = $at;
                $total = bcadd($total, number_format(abs((float) $s->total), 2, '.', ''), 2);
            }
        }

        $n = count($outside);

        if ($n < 2 || ($n < 3 && bccomp($total, '20', 2) < 0)) {
            return null;
        }

        $tz = TradingDay::timezone();
        $set = $week->forDate(CarbonImmutable::parse($w->day, $tz));
        $closed = $set === null;
        $severity = match (true) {
            $n >= 10 || bccomp($total, '200', 2) >= 0 => AnomalySeverity::High,
            $closed || $n >= 5 || bccomp($total, '50', 2) >= 0 => AnomalySeverity::Medium,
            default => AnomalySeverity::Low,
        };
        $first = $outside[0]->setTimezone($tz)->format('H:i');
        $last = $outside[$n - 1]->setTimezone($tz)->format('H:i');
        $hoursText = $closed ? 'Closed' : $set['opens'].'–'.$set['closes'];

        return new AnomalyFinding(
            kind: AnomalyKind::OutOfHours,
            severity: $severity,
            branchId: $branchId,
            day: $w->day,
            periodStart: $outside[0],
            periodEnd: $outside[$n - 1],
            title: $shop.': '.Fmt::count($n, 'sale').' outside opening hours on '.$w->dayLabel(),
            summary: $shop.' took '.Fmt::count($n, 'sale').' ('.Fmt::money($total).') '.($closed ? 'on a day it is set as closed' : 'outside its opening hours of '.$hoursText)
                .' on '.$w->dayLabel().', between '.$first.' and '.$last.'. If the shop really opened, update its hours; if not, check who was trading.',
            facts: [
                ['label' => 'Sales outside hours', 'value' => (string) $n],
                ['label' => 'Value', 'value' => Fmt::money($total)],
                ['label' => 'Opening hours that day', 'value' => $hoursText],
                ['label' => 'First and last', 'value' => $first.' and '.$last],
            ],
            links: [
                L::sales('Sales that day', $branchId, $w->day, $w->day),
                L::link('Opening hours', '/app/calendar'),
            ],
            score: (float) $n,
        );
    }

    /** Whether trading outside the hours is this shop's normal (its earliest or latest sale outside on 40%+ of days). */
    private function usuallyOutside(DetectionWindow $w, string $branchId, WeeklyHours $week, string $from): bool
    {
        $days = $this->sales($w->companyId, $branchId)->whereBetween('s.trading_day', [$from, $w->baselineTo()])
            ->groupBy('s.trading_day')->selectRaw('s.trading_day, MIN(s.completed_at) as first_at, MAX(s.completed_at) as last_at')->get();

        if ($days->isEmpty()) {
            return false;
        }

        $outside = $days->filter(fn ($d) => ! ShopTimes::inHours($week, CarbonImmutable::parse((string) $d->first_at, 'UTC'), self::GRACE_MINUTES)
            || ! ShopTimes::inHours($week, CarbonImmutable::parse((string) $d->last_at, 'UTC'), self::GRACE_MINUTES))->count();

        return $outside / $days->count() >= self::USUAL_SHARE;
    }

    private function sales(string $companyId, string $branchId): Builder
    {
        return DB::table('sales as s')->where('s.company_id', $companyId)->where('s.branch_id', $branchId)
            ->whereIn('s.type', SaleFacts::TRADING_TYPES)->where('s.status', 'completed')->whereNotNull('s.completed_at')->whereNull('s.deleted_at');
    }
}
