<?php

namespace App\Domain\Anomalies\Detectors;

use App\Domain\Anomalies\Data\AnomalyFinding;
use App\Domain\Anomalies\Data\DetectionWindow;
use App\Domain\Anomalies\Enums\AnomalyKind;
use App\Domain\Anomalies\Enums\AnomalySeverity;
use App\Domain\Anomalies\Support\AnomalyLinks as L;
use App\Domain\Anomalies\Support\Fmt;
use App\Domain\Anomalies\Support\RobustStats as R;
use App\Domain\Anomalies\Support\ShopTimes;
use App\Domain\Calendar\Support\WeeklyHours;
use App\Domain\Reporting\ReportTables;
use App\Domain\Reporting\Support\TradingDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Till down or shop shut? (module 6.6, hourly, today so far): the latest completed hours with no sales at all
 * (`rpt_sales_hourly`) where the same hour on the same weekday of the last 8 weeks normally trades — a usual
 * (median) of at least {@see self::MIN_EXPECTED} sales, with sales in at least {@see self::MIN_WEEKS} of the 8 weeks —
 * and, when the shop's opening hours are set, the shop is open the whole hour. At least {@see self::MIN_HOURS} such
 * hours in a row, ending with the last completed hour. One row per shop and day, updated as the gap grows.
 */
final class SalesGap implements Detector
{
    public const MIN_EXPECTED = 3.0;

    public const MIN_WEEKS = 6;

    public const MIN_HOURS = 2;

    public function runsIn(string $mode): bool
    {
        return $mode === DetectionWindow::HOURLY;
    }

    public function detect(DetectionWindow $window): array
    {
        $hour = TradingDay::currentHour($window->now);
        $weeks = $window->sameWeekdays();
        $rows = DB::table(ReportTables::SALES_HOURLY)->where('company_id', $window->companyId)
            ->whereIn('branch_id', array_keys($window->shops))->whereIn('trading_day', [$window->day, ...$weeks])
            ->groupBy('branch_id', 'trading_day', 'hour')->selectRaw('branch_id, trading_day, hour, SUM(txn_count) as n')->get();
        $counts = [];

        foreach ($rows as $r) {
            $counts[(string) $r->branch_id][substr((string) $r->trading_day, 0, 10)][(int) $r->hour] = (int) $r->n;
        }

        $hours = ShopTimes::for(array_keys($window->shops), $window->day, $window->day);
        $out = [];

        foreach ($window->shops as $branchId => $shop) {
            $finding = $this->shop($window, (string) $branchId, $shop, $hour, $counts[(string) $branchId] ?? [], $weeks, $hours[(string) $branchId] ?? null);

            if ($finding !== null) {
                $out[] = $finding;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, array<int, int>>  $counts  day => hour => sales
     * @param  list<string>  $weeks
     */
    private function shop(DetectionWindow $w, string $branchId, string $shop, int $hour, array $counts, array $weeks, ?WeeklyHours $hours): ?AnomalyFinding
    {
        $tradedWeeks = count(array_filter($weeks, fn (string $d) => array_sum($counts[$d] ?? []) > 0));

        if ($tradedWeeks < self::MIN_WEEKS) {
            return null;
        }

        $tz = TradingDay::timezone();
        $gap = [];

        for ($h = $hour - 1; $h >= 0; $h--) {
            $usual = array_map(fn (string $d) => (float) ($counts[$d][$h] ?? 0), $weeks);
            $from = CarbonImmutable::parse(sprintf('%s %02d:00', $w->day, $h), $tz)->utc();
            $open = $hours === null || ShopTimes::openThroughout($hours, $from, $from->addHour());
            $usualTrades = count(array_filter($usual, fn (float $n) => $n > 0)) >= self::MIN_WEEKS && R::median($usual) >= self::MIN_EXPECTED;

            if (($counts[$w->day][$h] ?? 0) > 0 || ! $open || ! $usualTrades) {
                break;
            }

            $gap[$h] = R::median($usual);
        }

        if (count($gap) < self::MIN_HOURS) {
            return null;
        }

        $first = min(array_keys($gap));
        $expected = array_sum($gap);
        $length = count($gap);
        $start = CarbonImmutable::parse(sprintf('%s %02d:00', $w->day, $first), $tz);
        $end = CarbonImmutable::parse(sprintf('%s %02d:00', $w->day, $hour), $tz);
        $severity = match (true) {
            $length >= 3 && $expected >= 20 => AnomalySeverity::High,
            $expected >= 10 => AnomalySeverity::Medium,
            default => AnomalySeverity::Low,
        };
        $todaySales = array_sum($counts[$w->day] ?? []);
        $weekday = $start->format('l');
        $span = $start->format('H:i').'–'.$end->format('H:i');

        return new AnomalyFinding(
            kind: AnomalyKind::SalesGap,
            severity: $severity,
            branchId: $branchId,
            day: $w->day,
            periodStart: $start->utc(),
            periodEnd: $end->utc(),
            title: $shop.': no sales since '.$start->format('H:i').' today',
            summary: 'No sales have reached the portal from '.$shop.' for '.Fmt::count($length, 'hour').' ('.$span.'). '
                .'On the last 8 '.$weekday.'s those hours took a usual '.Fmt::number($expected).' sales. '
                .'A till may be down or not syncing, or the shop may be shut.',
            facts: [
                ['label' => 'Hours without a sale', 'value' => (string) $length.' ('.$span.')'],
                ['label' => 'Sales in those hours', 'value' => '0', 'usual' => Fmt::number($expected)],
                ['label' => 'Sales today before that', 'value' => (string) $todaySales],
                ['label' => $weekday.'s compared', 'value' => (string) $tradedWeeks],
            ],
            links: [
                L::link('Till health for the shop', '/app/shops/'.$branchId),
                L::sales('Today\'s sales', $branchId, $w->day, $w->day),
            ],
            score: round($expected, 2),
        );
    }
}
