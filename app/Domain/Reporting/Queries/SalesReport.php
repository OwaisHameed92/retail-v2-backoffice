<?php

namespace App\Domain\Reporting\Queries;

use App\Domain\Reporting\Data\DaySales;
use App\Domain\Reporting\Data\GroupSales;
use App\Domain\Reporting\Data\HourSales;
use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Data\SalesComparison;
use App\Domain\Reporting\Data\SalesTotals;
use App\Domain\Reporting\Models\RptSalesDaily;
use App\Domain\Reporting\Models\RptSalesHourly;
use App\Domain\Reporting\ReportTables;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Sales figures from `rpt_sales_daily` / `rpt_sales_hourly` (DASHBOARD.md §2.2, §2.3, §5.1, §5.3) for the admin
 * trading dashboard (3.2), the business dashboard (3.3) and the sales report (4.8). Never reads raw till rows.
 *
 *     $report->totals(ReportScope::tenant($today, $today))->net;
 *     $report->compare($scope, $scope->previousPeriod())->changePercent('net');
 */
final class SalesReport
{
    private const T = ReportTables::SALES_DAILY;

    private const H = ReportTables::SALES_HOURLY;

    public function totals(ReportScope $scope): SalesTotals
    {
        $def = ReportTables::TABLES[self::T];
        $row = $scope->query(RptSalesDaily::class)->select(Sums::select(self::T, $def['decimals'], $def['counts']))->first();

        return SalesTotals::fromSums(Sums::read($row, $def['decimals'], $def['counts']));
    }

    public function compare(ReportScope $current, ReportScope $previous): SalesComparison
    {
        return new SalesComparison($this->totals($current), $this->totals($previous));
    }

    /**
     * Every day of the range, days without sales as zeros.
     *
     * @return list<DaySales>
     */
    public function byDay(ReportScope $scope): array
    {
        $rows = $this->grouped($scope->query(RptSalesDaily::class), self::T.'.trading_day')->get()->keyBy(fn ($r) => substr((string) $r->group_id, 0, 10));
        $days = [];

        for ($day = $scope->from; $day->lessThanOrEqualTo($scope->to); $day = $day->addDay()) {
            $s = $this->sums($rows->get($day->toDateString()));
            $days[] = new DaySales($day->toDateString(), (string) $s['gross'], (string) $s['net'], (string) $s['vat'], (int) $s['txn_count'], (int) $s['refund_count'], (string) $s['refund_gross'], (string) $s['takings']);
        }

        return $days;
    }

    /**
     * Hours 0–23 (up to `$upToHour` when given: "Today" against a whole day, §2.9).
     *
     * @return list<HourSales>
     */
    public function byHour(ReportScope $scope, ?int $upToHour = null): array
    {
        $decimals = ReportTables::TABLES[self::H]['decimals'];
        $rows = $scope->query(RptSalesHourly::class)
            ->when($upToHour !== null, fn (Builder $q) => $q->where(self::H.'.hour', '<=', $upToHour))
            ->groupBy(self::H.'.hour')
            ->select([self::H.'.hour', ...Sums::select(self::H, $decimals, ['txn_count'])])
            ->get()->keyBy(fn ($r) => (int) $r->hour);
        $hours = [];

        for ($hour = 0; $hour <= ($upToHour ?? 23); $hour++) {
            $s = Sums::read($rows->get($hour), $decimals, ['txn_count']);
            $hours[] = new HourSales($hour, (string) $s['net'], (string) $s['gross'], (int) $s['txn_count']);
        }

        return $hours;
    }

    /** Net, gross and transactions up to and including a local hour (compare Today up to now, §2.9). */
    public function upToHour(ReportScope $scope, int $hour): HourSales
    {
        $decimals = ReportTables::TABLES[self::H]['decimals'];
        $row = $scope->query(RptSalesHourly::class)->where(self::H.'.hour', '<=', $hour)
            ->select(Sums::select(self::H, $decimals, ['txn_count']))->first();
        $s = Sums::read($row, $decimals, ['txn_count']);

        return new HourSales($hour, (string) $s['net'], (string) $s['gross'], (int) $s['txn_count']);
    }

    /**
     * @return list<GroupSales>
     */
    public function byBranch(ReportScope $scope): array
    {
        $query = $scope->query(RptSalesDaily::class)
            ->leftJoin('branches as b', fn (JoinClause $j) => $j->on('b.id', '=', self::T.'.branch_id')->on('b.company_id', '=', self::T.'.company_id'));

        return $this->groups($this->grouped($query, self::T.'.branch_id', 'MAX(b.name)'));
    }

    /**
     * Label: register code – name (DASHBOARD.md §2.1).
     *
     * @return list<GroupSales>
     */
    public function byRegister(ReportScope $scope): array
    {
        $query = $scope->query(RptSalesDaily::class)
            ->leftJoin('registers as g', fn (JoinClause $j) => $j->on('g.id', '=', self::T.'.register_id')->on('g.company_id', '=', self::T.'.company_id'));

        return $this->groups($this->grouped($query, self::T.'.register_id', "MAX(g.code) || ' – ' || MAX(g.name)"));
    }

    /**
     * Per business: the admin trading dashboard only (3.2).
     *
     * @return list<GroupSales>
     */
    public function byCompany(ReportScope $scope): array
    {
        if (! $scope->admin) {
            throw new InvalidArgumentException('Sales per business are for the admin area only.');
        }

        $query = $scope->query(RptSalesDaily::class)->leftJoin('companies as c', 'c.id', '=', self::T.'.company_id');

        return $this->groups($this->grouped($query, self::T.'.company_id', 'MAX(c.name)'));
    }

    private function grouped(Builder $query, string $column, ?string $labelSql = null): Builder
    {
        $def = ReportTables::TABLES[self::T];

        return $query->groupBy($column)->select([
            DB::raw(DB::connection()->getQueryGrammar()->wrap($column).' as group_id'),
            DB::raw(($labelSql === null ? "''" : $this->concat($labelSql)).' as group_label'),
            ...Sums::select(self::T, $def['decimals'], $def['counts']),
        ]);
    }

    /**
     * @return list<GroupSales>
     */
    private function groups(Builder $query): array
    {
        $groups = [];

        foreach ($query->orderByRaw(Sums::orderExpression(self::T, 'net', 2).' desc')->get() as $row) {
            $s = $this->sums($row);
            $groups[] = new GroupSales(
                (string) $row->group_id,
                (string) ($row->group_label ?? '') !== '' ? (string) $row->group_label : 'Unknown',
                (string) $s['gross'], (string) $s['net'], (string) $s['vat'], (int) $s['txn_count'], (int) $s['refund_count'],
                (string) $s['refund_gross'], (string) $s['takings'], SalesTotals::average((string) $s['net'], (int) $s['txn_count']),
            );
        }

        return $groups;
    }

    /**
     * @return array<string, string|int>
     */
    private function sums(?object $row): array
    {
        $def = ReportTables::TABLES[self::T];

        return Sums::read($row, $def['decimals'], $def['counts']);
    }

    /** `a || b` is string concatenation on SQLite; MySQL needs CONCAT(). */
    private function concat(string $sql): string
    {
        if (DB::connection()->getDriverName() !== 'sqlite' && str_contains($sql, '||')) {
            return 'CONCAT('.implode(', ', array_map('trim', explode('||', $sql))).')';
        }

        return $sql;
    }
}
