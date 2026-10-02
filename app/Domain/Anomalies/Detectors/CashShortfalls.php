<?php

namespace App\Domain\Anomalies\Detectors;

use App\Domain\Anomalies\Data\AnomalyFinding;
use App\Domain\Anomalies\Data\DetectionWindow;
use App\Domain\Anomalies\Enums\AnomalyKind;
use App\Domain\Anomalies\Enums\AnomalySeverity;
use App\Domain\Anomalies\Support\AnomalyLinks as L;
use App\Domain\Anomalies\Support\Fmt;
use App\Domain\Cash\Queries\VarianceAlerts;
use App\Domain\Cash\Support\CashLookup;
use App\Domain\Reporting\Support\TradingDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cash shortfalls that keep happening (module 6.6, daily): closed shifts of the last {@see self::DAYS} days whose cash
 * difference (`ShiftTender.variance` of cash types, else the shift's total) is short by at least the shop's alert
 * amount (module 5.4, `cash.variance_alert_over`, default £5).
 *
 * - staff member (drawer owner, else who opened the shift): at least {@see self::MIN_SHORTS} shortfalls, one of them
 *   closed on the day examined, short on at least 30% of their shifts and at least twice the rest of the shop's rate;
 * - till: the same at one till across at least two staff members (points at the till or the process, not a person).
 */
final class CashShortfalls implements Detector
{
    public const DAYS = 28;

    public const MIN_SHORTS = 3;

    public const MIN_RATE = 0.3;

    public function runsIn(string $mode): bool
    {
        return $mode === DetectionWindow::DAILY;
    }

    public function detect(DetectionWindow $window): array
    {
        [$default, $byShop] = VarianceAlerts::thresholds();
        [$start] = TradingDay::window(CarbonImmutable::parse($window->day, 'UTC')->subDays(self::DAYS - 1)->toDateString());
        [$dayStart, $end] = $window->utcWindow();
        $cash = DB::table('shift_tenders as t')->join('payment_types as p', fn ($j) => $j->on('p.id', '=', 't.payment_type_id')->on('p.company_id', '=', 't.company_id'))
            ->where('t.company_id', $window->companyId)->where('p.is_cash', true)->whereNull('t.deleted_at')
            ->groupBy('t.shift_id')->selectRaw('t.shift_id, SUM(ROUND(t.variance * 100)) as v');
        $shifts = DB::table('shifts as s')->leftJoinSub($cash, 'c', 'c.shift_id', '=', 's.id')
            ->where('s.company_id', $window->companyId)->whereIn('s.branch_id', array_keys($window->shops))->whereNull('s.deleted_at')
            ->where('s.status', 'closed')->where('s.closed_at', '>=', $start->format('Y-m-d H:i:s'))->where('s.closed_at', '<', $end->format('Y-m-d H:i:s'))
            ->orderBy('s.closed_at')
            ->get(['s.id', 's.branch_id', 's.register_id', 's.user_id', 's.drawer_owner_user_id', 's.closed_at', 's.variance_total', 'c.v']);
        $byShop = $this->group($shifts->all(), $default, $byShop, $dayStart);
        $out = [];

        foreach ($byShop as $branchId => $rows) {
            $shop = $window->shops[$branchId] ?? 'Shop';
            $staff = CashLookup::staff(array_column($rows, 'staff'));
            $tills = CashLookup::tills(array_column($rows, 'till'));

            foreach (['staff' => AnomalyKind::StaffCashShortfalls, 'till' => AnomalyKind::TillCashShortfalls] as $by => $kind) {
                foreach (array_unique(array_column($rows, $by)) as $subject) {
                    $mine = array_values(array_filter($rows, fn (array $r) => $r[$by] === $subject));
                    $others = array_values(array_filter($rows, fn (array $r) => $r[$by] !== $subject));
                    $name = $by === 'staff' ? ($staff[$subject] ?? 'Unknown staff member') : ($tills[$subject] ?? 'A till');
                    $finding = $this->judge($window, (string) $branchId, $shop, $kind, (string) $subject, $name, $mine, $others);

                    if ($finding !== null) {
                        $out[] = $finding;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<object>  $shifts
     * @param  array<string, string>  $thresholds
     * @return array<string, list<array{id: string, staff: string, till: string, variance: string, short: bool, today: bool, at: string}>>
     */
    private function group(array $shifts, string $default, array $thresholds, CarbonImmutable $dayStart): array
    {
        $out = [];

        foreach ($shifts as $s) {
            $variance = $s->v !== null ? bcdiv((string) (int) $s->v, '100', 2) : number_format((float) ($s->variance_total ?? 0), 2, '.', '');
            $limit = $thresholds[(string) $s->branch_id] ?? $default;
            $out[(string) $s->branch_id][] = [
                'id' => (string) $s->id,
                'staff' => (string) ($s->drawer_owner_user_id ?: $s->user_id),
                'till' => (string) $s->register_id,
                'variance' => $variance,
                'short' => bccomp($variance, '-'.ltrim($limit, '-'), 2) <= 0 && bccomp($variance, '0', 2) < 0,
                'today' => CarbonImmutable::parse((string) $s->closed_at, 'UTC')->greaterThanOrEqualTo($dayStart),
                'at' => (string) $s->closed_at,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{id: string, staff: string, till: string, variance: string, short: bool, today: bool, at: string}>  $mine
     * @param  list<array{id: string, staff: string, till: string, variance: string, short: bool, today: bool, at: string}>  $others
     */
    private function judge(DetectionWindow $w, string $branchId, string $shop, AnomalyKind $kind, string $subject, string $name, array $mine, array $others): ?AnomalyFinding
    {
        $shorts = array_values(array_filter($mine, fn (array $r) => $r['short']));
        $rate = count($mine) > 0 ? count($shorts) / count($mine) : 0.0;
        $otherShorts = count(array_filter($others, fn (array $r) => $r['short']));
        $otherRate = count($others) > 0 ? $otherShorts / count($others) : null;
        $people = count(array_unique(array_column($shorts, 'staff')));

        if ($subject === '' || count($shorts) < self::MIN_SHORTS || ! in_array(true, array_column($shorts, 'today'), true) || $rate < self::MIN_RATE
            || ($otherRate !== null && $otherRate > 0 && $rate < 2 * $otherRate) || ($kind === AnomalyKind::TillCashShortfalls && $people < 2)) {
            return null;
        }

        $total = '0.00';
        $largest = '0.00';
        foreach ($shorts as $r) {
            $short = ltrim($r['variance'], '-');
            $total = bcadd($total, $short, 2);
            $largest = bccomp($short, $largest, 2) > 0 ? $short : $largest;
        }

        $severity = match (true) {
            bccomp($total, '100', 2) >= 0 || count($shorts) >= 5 => AnomalySeverity::High,
            bccomp($total, '30', 2) >= 0 => AnomalySeverity::Medium,
            default => AnomalySeverity::Low,
        };
        $from = CarbonImmutable::parse($w->day, 'UTC')->subDays(self::DAYS - 1)->toDateString();
        [$periodStart] = TradingDay::window($from);
        [, $periodEnd] = $w->utcWindow();
        $who = $kind === AnomalyKind::StaffCashShortfalls ? $name : $name.' at '.$shop;
        $pct = fn (float $r) => Fmt::rate($r * 100).'%';
        $latest = array_slice(array_reverse($shorts), 0, 3);

        return new AnomalyFinding(
            kind: $kind,
            severity: $severity,
            branchId: $branchId,
            day: $w->day,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            title: $who.': '.Fmt::count(count($shorts), 'cash shortfall').' in '.self::DAYS.' days ('.Fmt::money($total).')',
            summary: $who.($kind === AnomalyKind::StaffCashShortfalls ? ' at '.$shop : '').' was short at cash-up on '.count($shorts).' of '.Fmt::count(count($mine), 'shift')
                .' in the last '.self::DAYS.' days, '.Fmt::money($total).' in all, the latest on '.$w->dayLabel().'.'
                .($otherRate !== null ? ' The rest of the shop was short on '.$pct($otherRate).' of shifts.' : '')
                .($kind === AnomalyKind::TillCashShortfalls ? ' The shortfalls were under '.$people.' different staff members.' : ''),
            facts: [
                ['label' => 'Short shifts, last '.self::DAYS.' days', 'value' => count($shorts).' of '.count($mine)],
                ['label' => 'Short rate', 'value' => $pct($rate), 'peers' => $otherRate !== null ? $pct($otherRate) : null],
                ['label' => 'Total short', 'value' => Fmt::money($total)],
                ['label' => 'Largest shortfall', 'value' => Fmt::money($largest)],
                ...($kind === AnomalyKind::TillCashShortfalls ? [['label' => 'Staff members involved', 'value' => (string) $people]] : []),
            ],
            links: [
                ...array_map(fn (array $r) => L::shift('Shift closed '.CarbonImmutable::parse($r['at'], 'UTC')->setTimezone(TradingDay::timezone())->format('j M H:i').' ('.Fmt::money(ltrim($r['variance'], '-')).' short)', $r['id']), $latest),
                L::link('Cash variance alerts', '/app/cash/alerts', ['from' => $from, 'to' => $w->day, 'shop' => $branchId]),
                ...($kind === AnomalyKind::StaffCashShortfalls ? [L::staff($subject)] : []),
            ],
            score: (float) count($shorts),
            subjectId: $kind === AnomalyKind::StaffCashShortfalls ? $subject : null,
            subjectName: $kind === AnomalyKind::StaffCashShortfalls ? $name : null,
            registerId: $kind === AnomalyKind::TillCashShortfalls ? $subject : null,
        );
    }
}
