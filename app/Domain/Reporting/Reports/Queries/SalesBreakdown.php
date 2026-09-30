<?php

namespace App\Domain\Reporting\Reports\Queries;

use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Data\SalesTotals;
use App\Domain\Reporting\Models\RptSalesDaily;
use App\Domain\Reporting\Queries\Sums;
use App\Domain\Reporting\Reports\ReportGrouping;
use App\Domain\Reporting\ReportTables;
use App\Domain\Shared\Support\Money;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Every `rpt_sales_daily` column summed per period, shop or till (module 4.8): one grouped query each. The 3.1
 * `SalesReport` groups carry only the dashboard's columns; reports also need discounts, voids and refunds per group.
 */
final class SalesBreakdown
{
    private const T = ReportTables::SALES_DAILY;

    /**
     * Every period of the range (days without sales as zeros), oldest first.
     *
     * @return list<array{id: string, label: string, totals: SalesTotals}>
     */
    public function byPeriod(ReportScope $scope, ReportGrouping $group): array
    {
        $rows = $scope->query(RptSalesDaily::class)->groupBy(self::T.'.trading_day')
            ->select([DB::raw(self::T.'.trading_day as day'), ...$this->sums()])->get();
        $buckets = [];

        for ($day = $scope->from; $day->lessThanOrEqualTo($scope->to); $day = $day->addDay()) {
            $buckets[$group->key($day->toDateString())] ??= self::read(null);
        }

        foreach ($rows as $row) {
            $key = $group->key(substr((string) $row->day, 0, 10));
            $buckets[$key] = self::add($buckets[$key] ?? self::read(null), self::read($row));
        }

        $out = [];

        foreach ($buckets as $key => $sums) {
            $out[] = ['id' => (string) $key, 'label' => $group->label((string) $key), 'totals' => SalesTotals::fromSums($sums)];
        }

        return $out;
    }

    /**
     * @return list<array{id: string, label: string, totals: SalesTotals}>
     */
    public function byBranch(ReportScope $scope): array
    {
        $rows = $scope->query(RptSalesDaily::class)
            ->leftJoin('branches as b', fn (JoinClause $j) => $j->on('b.id', '=', self::T.'.branch_id')->on('b.company_id', '=', self::T.'.company_id'))
            ->groupBy(self::T.'.branch_id')
            ->select([DB::raw(self::T.'.branch_id as id'), DB::raw('MAX(b.name) as name'), ...$this->sums()])
            ->orderByRaw(Sums::orderExpression(self::T, 'net', 2).' desc')->get();

        return $rows->map(fn ($r) => ['id' => (string) $r->id, 'label' => (string) ($r->name ?? '') !== '' ? (string) $r->name : 'Unknown shop', 'totals' => SalesTotals::fromSums(self::read($r))])->values()->all();
    }

    /**
     * Label "code – name" (DASHBOARD.md §2.1), with the shop's name.
     *
     * @return list<array{id: string, label: string, shop: string, totals: SalesTotals}>
     */
    public function byRegister(ReportScope $scope): array
    {
        $rows = $scope->query(RptSalesDaily::class)
            ->leftJoin('registers as g', fn (JoinClause $j) => $j->on('g.id', '=', self::T.'.register_id')->on('g.company_id', '=', self::T.'.company_id'))
            ->leftJoin('branches as b', fn (JoinClause $j) => $j->on('b.id', '=', self::T.'.branch_id')->on('b.company_id', '=', self::T.'.company_id'))
            ->groupBy(self::T.'.register_id')
            ->select([DB::raw(self::T.'.register_id as id'), DB::raw('MAX(g.code) as code'), DB::raw('MAX(g.name) as name'), DB::raw('MAX(b.name) as shop'), ...$this->sums()])
            ->orderByRaw(Sums::orderExpression(self::T, 'net', 2).' desc')->get();

        return $rows->map(function ($r) {
            $label = trim((string) ($r->code ?? '').' – '.(string) ($r->name ?? ''), ' –');

            return ['id' => (string) $r->id, 'label' => $label !== '' ? $label : 'Unknown till', 'shop' => (string) ($r->shop ?? ''), 'totals' => SalesTotals::fromSums(self::read($r))];
        })->values()->all();
    }

    /**
     * @return list<Expression<non-falsy-string>>
     */
    private function sums(): array
    {
        $def = ReportTables::TABLES[self::T];

        return Sums::select(self::T, $def['decimals'], $def['counts']);
    }

    /**
     * @return array<string, string|int>
     */
    private static function read(?object $row): array
    {
        $def = ReportTables::TABLES[self::T];

        return Sums::read($row, $def['decimals'], $def['counts']);
    }

    /**
     * @param  array<string, string|int>  $a
     * @param  array<string, string|int>  $b
     * @return array<string, string|int>
     */
    private static function add(array $a, array $b): array
    {
        $def = ReportTables::TABLES[self::T];

        foreach ($def['decimals'] as $column => $scale) {
            $a[$column] = Money::add($a[$column], $b[$column], $scale);
        }

        foreach ($def['counts'] as $column) {
            $a[$column] = (int) $a[$column] + (int) $b[$column];
        }

        return $a;
    }
}
