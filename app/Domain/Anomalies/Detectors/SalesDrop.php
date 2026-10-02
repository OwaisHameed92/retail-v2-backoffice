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
use App\Domain\Reporting\ReportTables;
use App\Domain\Reporting\Support\Units;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A day's sales far below the shop's normal for that weekday (module 6.6, daily, yesterday): `rpt_sales_daily` sales
 * (inc VAT) against the same weekday in the last 8 weeks. Needs sales on at least {@see self::MIN_WEEKS} of those 8
 * days, a usual day of at least {@see self::MIN_USUAL} pounds, the day at or below half of usual and a robust score
 * of −3.5 or lower (spread at least 15% of usual). Skipped when the shop's hours say it was closed that day.
 */
final class SalesDrop implements Detector
{
    public const MIN_WEEKS = 6;

    public const MIN_USUAL = 100.0;

    public function runsIn(string $mode): bool
    {
        return $mode === DetectionWindow::DAILY;
    }

    public function detect(DetectionWindow $window): array
    {
        $weeks = $window->sameWeekdays();
        $rows = DB::table(ReportTables::SALES_DAILY)->where('company_id', $window->companyId)
            ->whereIn('branch_id', array_keys($window->shops))->whereIn('trading_day', [$window->day, ...$weeks])
            ->groupBy('branch_id', 'trading_day')
            ->select(['branch_id', 'trading_day', Units::sum('gross', 2, 'gross'), DB::raw('SUM(txn_count) as txn')])->get();
        $days = [];

        foreach ($rows as $r) {
            $days[(string) $r->branch_id][substr((string) $r->trading_day, 0, 10)] = ['gross' => Units::decimal(Units::of($r->gross), 2), 'txn' => (int) $r->txn];
        }

        $hours = ShopTimes::for(array_keys($window->shops), $window->day, $window->day);
        $out = [];

        foreach ($window->shops as $branchId => $shop) {
            $branchId = (string) $branchId;
            $byDay = $days[$branchId] ?? [];
            $usual = array_values(array_map(fn (string $d) => (float) $byDay[$d]['gross'], array_filter($weeks, fn (string $d) => ($byDay[$d]['txn'] ?? 0) > 0)));

            if (count($usual) < self::MIN_WEEKS || (isset($hours[$branchId]) && ShopTimes::closedOn($hours[$branchId], $window->day))) {
                continue;
            }

            $median = R::median($usual);
            $gross = $byDay[$window->day]['gross'] ?? '0.00';
            $x = (float) $gross;
            $score = R::score($x, $usual, 20.0, 0.15);

            if ($median < self::MIN_USUAL || $x > $median / 2 || $score > -R::UNUSUAL) {
                continue;
            }

            $usualTxn = R::median(array_map(fn (string $d) => (float) ($byDay[$d]['txn'] ?? 0), $weeks));
            $txn = $byDay[$window->day]['txn'] ?? 0;
            $down = Fmt::rate((1 - $x / $median) * 100).'%';
            $weekday = CarbonImmutable::parse($window->day, 'UTC')->format('l');
            [$start, $end] = $window->utcWindow();

            $out[] = new AnomalyFinding(
                kind: AnomalyKind::SalesDrop,
                severity: match (true) {
                    $x <= 0.0 => AnomalySeverity::High,
                    $x <= $median / 4 => AnomalySeverity::Medium,
                    default => AnomalySeverity::Low,
                },
                branchId: $branchId,
                day: $window->day,
                periodStart: $start,
                periodEnd: $end,
                title: $x <= 0.0
                    ? $shop.': no sales on '.$window->dayLabel()
                    : $shop.' took '.Fmt::money($gross).' on '.$window->dayLabel().', '.$down.' below a usual '.$weekday,
                summary: $shop.' took '.Fmt::money($gross).' ('.Fmt::count($txn, 'sale').') on '.$window->dayLabel().' against a usual '
                    .Fmt::pounds($median).' ('.Fmt::number($usualTxn).' sales) on the last '.count($usual).' '.$weekday.'s. '
                    .'A till may not have synced, or the shop may have opened late or shut early.',
                facts: [
                    ['label' => 'Sales (inc VAT)', 'value' => Fmt::money($gross), 'usual' => Fmt::pounds($median)],
                    ['label' => 'Transactions', 'value' => (string) $txn, 'usual' => Fmt::number($usualTxn)],
                    ['label' => 'Below usual', 'value' => $down],
                    ['label' => $weekday.'s compared', 'value' => (string) count($usual)],
                ],
                links: [
                    L::sales('Sales that day', $branchId, $window->day, $window->day),
                    L::link('Till health for the shop', '/app/shops/'.$branchId),
                ],
                score: round(abs($score), 2),
            );
        }

        return $out;
    }
}
