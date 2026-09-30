<?php

namespace App\Domain\Reporting\Queries;

use App\Domain\Reporting\Data\LeaderSales;
use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Data\SalesTotals;
use App\Domain\Reporting\Data\TradingActivity;
use App\Domain\Reporting\Models\RptSalesDaily;
use App\Domain\Reporting\ReportTables;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Admin-only read queries over `rpt_sales_daily` for the trading dashboard (module 3.2, DASHBOARD.md §3 "Sales
 * across all businesses"): the leading businesses and shops, how many businesses, shops and tills traded, and how
 * fresh the figures are. Grouped SQL with a LIMIT, so 1,000 businesses cost the same number of queries as one.
 */
final class AdminTradingReport
{
    private const T = ReportTables::SALES_DAILY;

    /**
     * Businesses ranked by net sales; `parentLabel` is empty, `children` = shops with rows in the range.
     *
     * @return list<LeaderSales>
     */
    public function topCompanies(ReportScope $scope, int $limit = 10): array
    {
        $query = $this->admin($scope)->leftJoin('companies as c', 'c.id', '=', self::T.'.company_id')
            ->groupBy(self::T.'.company_id')
            ->select([
                DB::raw(self::T.'.company_id as group_id'),
                DB::raw('MAX(c.name) as group_label'),
                DB::raw("'' as parent_id"),
                DB::raw("'' as parent_label"),
                DB::raw('COUNT(DISTINCT '.self::T.'.branch_id) as children'),
            ]);

        return $this->ranked($query, $limit);
    }

    /**
     * Shops ranked by net sales, with their business.
     *
     * @return list<LeaderSales>
     */
    public function topBranches(ReportScope $scope, int $limit = 10): array
    {
        $query = $this->admin($scope)
            ->leftJoin('branches as b', fn (JoinClause $j) => $j->on('b.id', '=', self::T.'.branch_id')->on('b.company_id', '=', self::T.'.company_id'))
            ->leftJoin('companies as c', 'c.id', '=', self::T.'.company_id')
            ->groupBy(self::T.'.company_id', self::T.'.branch_id')
            ->select([
                DB::raw(self::T.'.branch_id as group_id'),
                DB::raw('MAX(b.name) as group_label'),
                DB::raw(self::T.'.company_id as parent_id'),
                DB::raw('MAX(c.name) as parent_label'),
                DB::raw('COUNT(DISTINCT '.self::T.'.register_id) as children'),
            ]);

        return $this->ranked($query, $limit);
    }

    /** Businesses, shops and tills with a reporting row in the range (they traded, refunded or voided). */
    public function activity(ReportScope $scope): TradingActivity
    {
        $row = $this->admin($scope)->select([
            DB::raw('COUNT(DISTINCT '.self::T.'.company_id) as companies'),
            DB::raw('COUNT(DISTINCT '.self::T.'.branch_id) as branches'),
            DB::raw('COUNT(DISTINCT '.self::T.'.register_id) as registers'),
            DB::raw('MAX('.self::T.'.rebuilt_at) as rebuilt_at'),
        ])->first();

        $dirty = DB::table(ReportTables::DIRTY_DAYS)
            ->when($scope->companyId !== null, fn (Builder $q) => $q->where('company_id', $scope->companyId))
            ->when($scope->branchIds !== null, fn (Builder $q) => $q->whereIn('branch_id', $scope->branchIds ?? []))
            ->whereBetween('trading_day', [$scope->from->toDateString(), $scope->to->toDateString()]);

        $pushes = DB::table('sync_branch_status')
            ->when($scope->companyId !== null, fn (Builder $q) => $q->where('company_id', $scope->companyId))
            ->when($scope->branchIds !== null, fn (Builder $q) => $q->whereIn('branch_id', $scope->branchIds ?? []));

        return new TradingActivity(
            (int) ($row->companies ?? 0),
            (int) ($row->branches ?? 0),
            (int) ($row->registers ?? 0),
            self::utc($row->rebuilt_at ?? null),
            self::utc($pushes->max('last_push_at')),
            $dirty->count(),
        );
    }

    private function admin(ReportScope $scope): Builder
    {
        if (! $scope->admin) {
            throw new InvalidArgumentException('Cross-business trading figures are for the admin area only.');
        }

        return $scope->query(RptSalesDaily::class);
    }

    /**
     * @return list<LeaderSales>
     */
    private function ranked(Builder $query, int $limit): array
    {
        $def = ReportTables::TABLES[self::T];
        $rows = $query->addSelect(Sums::select(self::T, $def['decimals'], $def['counts']))
            ->orderByRaw(Sums::orderExpression(self::T, 'net', 2).' desc')
            ->orderBy('group_id')
            ->limit(max(1, $limit))
            ->get();
        $out = [];

        foreach ($rows as $row) {
            $s = Sums::read($row, $def['decimals'], $def['counts']);
            $label = (string) ($row->group_label ?? '');
            $out[] = new LeaderSales(
                (string) $row->group_id, $label !== '' ? $label : 'Unknown',
                (string) ($row->parent_id ?? ''), (string) ($row->parent_label ?? ''), (int) ($row->children ?? 0),
                (string) $s['gross'], (string) $s['net'], (int) $s['txn_count'], (int) $s['refund_count'], (string) $s['refund_gross'],
                SalesTotals::average((string) $s['net'], (int) $s['txn_count']),
            );
        }

        return $out;
    }

    private static function utc(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? substr(str_replace(' ', 'T', $value), 0, 19).'Z' : null;
    }
}
