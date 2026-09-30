<?php

namespace App\Domain\Reporting\Reports\Queries;

use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Models\RptProductDaily;
use App\Domain\Reporting\Queries\Sums;
use App\Domain\Reporting\ReportTables;
use App\Domain\Reporting\Support\Units;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * `rpt_product_daily` per product and per department with cost (module 4.8), for product sales, refunds and
 * discounts. Department, category, name and code are the product's current ones, joined when read (3.1's rule).
 * Qty = sold − returned (base units); money net of refunds.
 */
final class ProductBreakdown
{
    private const T = ReportTables::PRODUCT_DAILY;

    public const SUMMED = ['qty' => 4, 'refund_qty' => 4, 'gross' => 2, 'net' => 2, 'vat' => 2, 'refund_net' => 2, 'discount' => 2, 'promo' => 2, 'cost' => 4];

    /**
     * Products ranked by a column (`net`, `refund_net`, `discount`), optionally only those with some of it.
     *
     * @param  'net'|'refund_net'|'discount'  $rankBy
     * @return list<array<string, string|null>>
     */
    public function products(ReportScope $scope, int $limit, int $offset = 0, string $rankBy = 'net', bool $onlyWithRank = false): array
    {
        $rank = Sums::orderExpression(self::T, $rankBy, 2);
        $rows = $this->joined($scope)
            ->groupBy(self::T.'.product_id')
            ->when($onlyWithRank, fn (Builder $q) => $q->havingRaw($rank.' <> 0'))
            ->select([
                self::T.'.product_id',
                DB::raw('MAX(p.name) as product_name'),
                DB::raw('MAX(p.sku) as sku'),
                DB::raw('MAX('.self::T.'.last_name) as line_name'),
                DB::raw("MAX(COALESCE(d.name, 'Unassigned')) as department"),
                DB::raw("MAX(COALESCE(c.name, 'Unassigned')) as category"),
                ...Sums::select(self::T, self::SUMMED),
            ])
            ->orderByRaw($rank.' desc')->orderBy(self::T.'.product_id')
            ->offset($offset)->limit(max(1, $limit))->get();

        return $rows->map(function ($row) {
            $s = Sums::read($row, self::SUMMED);
            $name = (string) ($row->product_name ?? '') !== '' ? (string) $row->product_name : (string) ($row->line_name ?? '');

            return [
                'productId' => (string) $row->product_id, 'name' => $name !== '' ? $name : 'Unknown product', 'sku' => (string) ($row->sku ?? ''),
                'department' => (string) $row->department, 'category' => (string) $row->category,
                ...array_map('strval', $s), 'soldQty' => (string) $s['qty'], 'qty' => self::netQty($s),
            ];
        })->values()->all();
    }

    /** Number of products with sales (or returns) in the scope. */
    public function productCount(ReportScope $scope): int
    {
        return (int) $scope->query(RptProductDaily::class)->distinct()->count(self::T.'.product_id');
    }

    /**
     * @return list<array<string, string|null>>
     */
    public function departments(ReportScope $scope): array
    {
        $rows = $this->joined($scope)
            ->groupBy('d.id')
            ->select([DB::raw('d.id as group_id'), DB::raw('MAX(d.name) as group_name'), ...Sums::select(self::T, self::SUMMED)])
            ->orderByRaw(Sums::orderExpression(self::T, 'net', 2).' desc')->get();

        return $rows->map(function ($row) {
            $s = Sums::read($row, self::SUMMED);

            return ['id' => $row->group_id === null ? null : (string) $row->group_id, 'name' => $row->group_id === null ? 'Unassigned' : (string) ($row->group_name ?? 'Unassigned'), ...array_map('strval', $s), 'soldQty' => (string) $s['qty'], 'qty' => self::netQty($s)];
        })->values()->all();
    }

    /**
     * Every column summed over the scope (the products table's totals).
     *
     * @return array<string, string>
     */
    public function totals(ReportScope $scope): array
    {
        $s = Sums::read($scope->query(RptProductDaily::class)->select(Sums::select(self::T, self::SUMMED))->first(), self::SUMMED);

        return [...array_map('strval', $s), 'soldQty' => (string) $s['qty'], 'qty' => self::netQty($s)];
    }

    private function joined(ReportScope $scope): Builder
    {
        return $scope->query(RptProductDaily::class)
            ->leftJoin('products as p', fn (JoinClause $j) => $j->on('p.id', '=', self::T.'.product_id')->on('p.company_id', '=', self::T.'.company_id'))
            ->leftJoin('departments as d', fn (JoinClause $j) => $j->on('d.id', '=', 'p.department_id')->on('d.company_id', '=', 'p.company_id'))
            ->leftJoin('categories as c', fn (JoinClause $j) => $j->on('c.id', '=', 'p.category_id')->on('c.company_id', '=', 'p.company_id'));
    }

    /**
     * @param  array<string, string|int>  $s
     */
    private static function netQty(array $s): string
    {
        return Units::decimal(Units::sub(Units::fromDecimal($s['qty'], 4), Units::fromDecimal($s['refund_qty'], 4)), 4);
    }
}
