<?php

namespace App\Domain\Reporting\Queries;

use App\Domain\Reporting\Data\LineGroupSales;
use App\Domain\Reporting\Data\ProductSales;
use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Models\RptProductDaily;
use App\Domain\Reporting\ReportTables;
use App\Domain\Reporting\Support\Units;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * "Top products" and "By department / category" (DASHBOARD.md §2.3, §5.4) from `rpt_product_daily`. Department
 * and category are the product's **current** ones, joined at query time (the till groups history the same way);
 * a product the portal does not hold is "Unassigned". Qty = sold − returned, in base units; money net of refunds.
 */
final class ProductReport
{
    private const T = ReportTables::PRODUCT_DAILY;

    private const SUMMED = ['qty' => 4, 'refund_qty' => 4, 'gross' => 2, 'net' => 2, 'vat' => 2, 'refund_net' => 2, 'discount' => 2, 'cost' => 4];

    /**
     * @param  'net'|'qty'  $rankBy
     * @return list<ProductSales>
     */
    public function top(ReportScope $scope, int $limit = 10, string $rankBy = 'net'): array
    {
        $rank = $rankBy === 'qty'
            ? '('.Sums::orderExpression(self::T, 'qty', 4).' - '.Sums::orderExpression(self::T, 'refund_qty', 4).')'
            : Sums::orderExpression(self::T, 'net', 2);

        $rows = $this->joined($scope)
            ->groupBy(self::T.'.product_id')
            ->select([
                self::T.'.product_id',
                DB::raw('MAX(p.name) as product_name'),
                DB::raw('MAX('.self::T.'.last_name) as line_name'),
                DB::raw("MAX(COALESCE(d.name, 'Unassigned')) as department"),
                DB::raw("MAX(COALESCE(c.name, 'Unassigned')) as category"),
                ...Sums::select(self::T, self::SUMMED),
            ])
            ->orderByRaw($rank.' desc')->orderBy(self::T.'.product_id')
            ->limit(max(1, $limit))->get();

        $out = [];

        foreach ($rows as $row) {
            $s = Sums::read($row, self::SUMMED);
            $name = (string) ($row->product_name ?? '') !== '' ? (string) $row->product_name : (string) ($row->line_name ?? '');
            $out[] = new ProductSales(
                (string) $row->product_id, $name !== '' ? $name : 'Unknown product', (string) $row->department, (string) $row->category,
                self::netQty($s), (string) $s['refund_qty'], (string) $s['net'], (string) $s['gross'], (string) $s['vat'],
                (string) $s['refund_net'], (string) $s['discount'], (string) $s['cost'],
            );
        }

        return $out;
    }

    /**
     * @return list<LineGroupSales>
     */
    public function byDepartment(ReportScope $scope): array
    {
        return $this->byGroup($scope, 'd');
    }

    /**
     * @return list<LineGroupSales>
     */
    public function byCategory(ReportScope $scope): array
    {
        return $this->byGroup($scope, 'c');
    }

    /**
     * @param  'd'|'c'  $alias
     * @return list<LineGroupSales>
     */
    private function byGroup(ReportScope $scope, string $alias): array
    {
        $sums = ['qty' => 4, 'refund_qty' => 4, 'net' => 2, 'gross' => 2];
        $rows = $this->joined($scope)
            ->groupBy("{$alias}.id")
            ->select([DB::raw("{$alias}.id as group_id"), DB::raw("MAX({$alias}.name) as group_name"), ...Sums::select(self::T, $sums)])
            ->orderByRaw(Sums::orderExpression(self::T, 'net', 2).' desc')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $s = Sums::read($row, $sums);
            $id = $row->group_id === null ? null : (string) $row->group_id;
            $out[] = new LineGroupSales($id, $id === null ? 'Unassigned' : (string) ($row->group_name ?? 'Unassigned'), self::netQty($s), (string) $s['net'], (string) $s['gross']);
        }

        return $out;
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
