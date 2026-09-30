<?php

namespace App\Domain\Reporting\Reports\Builders;

use App\Domain\Reporting\Models\RptSalesHourly;
use App\Domain\Reporting\Queries\Sums;
use App\Domain\Reporting\Reports\Figures;
use App\Domain\Reporting\Reports\ReportBuilder;
use App\Domain\Reporting\Reports\ReportOptions;
use App\Domain\Reporting\Reports\ReportResult;
use App\Domain\Reporting\Reports\ReportTable;
use App\Domain\Reporting\ReportTables;
use App\Domain\Shared\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Busy hours: `rpt_sales_hourly` (local hours, 3.1) as a weekday × hour heatmap. One grouped query per trading day
 * and hour; weekdays are folded in PHP (no engine-specific date functions). Averages are per weekday in the range
 * (four Mondays → the Monday total ÷ 4), so a range of any length reads as "a typical Monday".
 */
final class HourlyReport implements ReportBuilder
{
    private const T = ReportTables::SALES_HOURLY;

    private const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    public function build(ReportOptions $options): ReportResult
    {
        $scope = $options->scope();
        $decimals = ReportTables::TABLES[self::T]['decimals'];
        $rows = $scope->query(RptSalesHourly::class)
            ->groupBy(self::T.'.trading_day', self::T.'.hour')
            ->select([DB::raw(self::T.'.trading_day as day'), self::T.'.hour', ...Sums::select(self::T, $decimals, ['txn_count'])])->get();

        $days = array_fill(1, 7, 0);

        for ($d = $scope->from; $d->lessThanOrEqualTo($scope->to); $d = $d->addDay()) {
            $days[$d->isoWeekday()]++;
        }

        $cells = [];
        $byHour = [];

        foreach ($rows as $row) {
            $weekday = CarbonImmutable::parse(substr((string) $row->day, 0, 10), 'UTC')->isoWeekday();
            $hour = (int) $row->hour;
            $s = Sums::read($row, $decimals, ['txn_count']);
            $cell = $cells[$weekday][$hour] ?? ['net' => '0.00', 'transactions' => 0];
            $cells[$weekday][$hour] = ['net' => Money::add($cell['net'], (string) $s['net']), 'transactions' => $cell['transactions'] + (int) $s['txn_count']];
            $h = $byHour[$hour] ?? ['net' => '0.00', 'transactions' => 0];
            $byHour[$hour] = ['net' => Money::add($h['net'], (string) $s['net']), 'transactions' => $h['transactions'] + (int) $s['txn_count']];
        }

        $hours = $byHour === [] ? range(8, 20) : range(min(array_keys($byHour)), max(array_keys($byHour)));
        $heat = [];
        $weekRows = [];

        foreach (self::WEEKDAYS as $n => $name) {
            $line = [];

            foreach ($hours as $hour) {
                $c = $cells[$n][$hour] ?? ['net' => '0.00', 'transactions' => 0];
                $line[] = ['hour' => $hour, 'net' => $c['net'], 'transactions' => $c['transactions'], 'averageNet' => $days[$n] > 0 ? Figures::average($c['net'], $days[$n]) : null];
            }

            $net = Money::sum(array_column($line, 'net'));
            $txns = array_sum(array_column($line, 'transactions'));
            $heat[] = ['weekday' => $n, 'label' => substr($name, 0, 3), 'days' => $days[$n], 'cells' => $line];
            $weekRows[] = ['label' => $name, 'days' => $days[$n], 'transactions' => $txns, 'net' => $net, 'averageNet' => $days[$n] > 0 ? Figures::average($net, $days[$n]) : null];
        }

        $totalDays = $scope->days();
        $hourRows = array_map(fn (int $hour) => [
            'label' => sprintf('%02d:00–%02d:00', $hour, ($hour + 1) % 24),
            'transactions' => $byHour[$hour]['transactions'] ?? 0,
            'net' => $byHour[$hour]['net'] ?? '0.00',
            'averageNet' => Figures::average($byHour[$hour]['net'] ?? '0.00', $totalDays),
        ], $hours);

        $ranked = $hourRows;
        usort($ranked, fn (array $a, array $b) => Money::compare($b['net'], $a['net']));
        $busiest = $ranked[0];
        $net = Money::sum(array_column($hourRows, 'net'));
        $txns = array_sum(array_column($hourRows, 'transactions'));

        return new ReportResult([
            Figures::of('net', 'Net sales', $net),
            Figures::of('transactions', 'Transactions', $txns, 'count'),
            Figures::of('busiest', 'Busiest hour', $byHour === [] ? null : (string) $busiest['label'], 'text', hint: $byHour === [] ? null : '£'.number_format((float) $busiest['net'], 2).' net sales'),
            Figures::of('perDay', 'Average day', Figures::average($net, $totalDays), 'money', hint: $totalDays.' days in range'),
        ], [
            new ReportTable('hours', 'By hour', [
                ReportTable::col('label', 'Hour'),
                ReportTable::col('transactions', 'Transactions', 'count'),
                ReportTable::col('net', 'Net sales', 'money'),
                ReportTable::col('averageNet', 'Average per day', 'money'),
            ], $hourRows, ['label' => 'Total', 'transactions' => $txns, 'net' => $net, 'averageNet' => Figures::average($net, $totalDays)]),
            new ReportTable('weekdays', 'By day of the week', [
                ReportTable::col('label', 'Day'),
                ReportTable::col('days', 'Days in range', 'count'),
                ReportTable::col('transactions', 'Transactions', 'count'),
                ReportTable::col('net', 'Net sales', 'money'),
                ReportTable::col('averageNet', 'Average day', 'money'),
            ], $weekRows),
        ], ['type' => 'heatmap', 'hours' => $hours, 'rows' => $heat], ['Hours are shop time (Europe/London), when each sale was completed. Sales exclude VAT.']);
    }
}
