<?php

namespace App\Domain\Reporting\Dashboard;

use App\Domain\Reporting\Data\GroupSales;
use App\Domain\Reporting\Data\LineGroupSales;
use App\Domain\Reporting\Data\ProductSales;
use App\Domain\Reporting\Data\StaffSales;
use App\Domain\Reporting\Queries\OperationsReport;
use App\Domain\Reporting\Queries\ProductReport;
use App\Domain\Reporting\Queries\SalesReport;
use App\Domain\Reporting\Queries\StaffReport;
use App\Domain\Reporting\Queries\TenderReport;
use App\Domain\Reporting\Queries\VatReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The business dashboard (module 3.3): every Business-panel tile and chart of DASHBOARD.md §2 for the current
 * company, all its shops or one (a one-shop user only theirs), optionally one till:
 *
 *     $dashboard->for(BusinessDashboardFilters::resolve($companyId, TradingPeriod::Today, null, null, TradingCompare::PreviousPeriod));
 *
 * Sales figures come only from the `rpt_*` tables through the 3.1 read side (tenant ReportScope, never raw sales);
 * low stock, cash variance and orders ready from the till's own rows ({@see OperationsReport}). About 20 grouped
 * queries whatever the number of sales, cached for CACHE_SECONDS per company and filter set.
 */
final class BusinessDashboard
{
    public const CACHE_SECONDS = 60;

    public const TOP_PRODUCTS = 10;

    public const TOP_GROUPS = 8;

    public function __construct(
        private readonly SalesReport $sales,
        private readonly TenderReport $tenders,
        private readonly VatReport $vat,
        private readonly ProductReport $products,
        private readonly StaffReport $staff,
        private readonly OperationsReport $operations,
        private readonly DashboardKpis $kpis,
        private readonly DashboardSeries $series,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(BusinessDashboardFilters $filters): array
    {
        /** @var array<string, mixed> */
        return Cache::remember($filters->cacheKey(), self::CACHE_SECONDS, fn () => $this->compute($filters, CarbonImmutable::now()));
    }

    /**
     * @return array<string, mixed>
     */
    public function compute(BusinessDashboardFilters $filters, CarbonImmutable $now): array
    {
        $scope = $filters->scope();
        $compare = $filters->compareScope();
        $daily = $this->series->daily($filters);
        $hourly = $this->series->hourly($filters);
        $spark = DashboardSeries::sparklines($daily, $hourly);
        $level = $filters->level();
        $rows = fn (array $items) => array_map(fn (object $item) => (array) $item, $items);

        return [
            'generatedAt' => $now->utc()->format('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'range' => [
                'from' => $scope->from->toDateString(),
                'to' => $scope->to->toDateString(),
                'days' => $scope->days(),
                'isToday' => $filters->isToday(),
                'today' => $filters->today->toDateString(),
                'hour' => $filters->hour,
                'compareFrom' => $compare?->from->toDateString(),
                'compareTo' => $compare?->to->toDateString(),
                'compareLabel' => $filters->compare->versus($filters->singleDay(), $filters->isToday()),
            ],
            'kpis' => $this->kpis->build($filters, $spark['net'], $spark['gross'], $spark['transactions']),
            'daily' => $daily,
            'hourly' => $hourly,
            'tenders' => $rows($this->tenders->byPaymentType($scope)),
            'vat' => $rows($this->vat->byRate($scope)),
            'shops' => $level === 'business' ? array_map(fn (GroupSales $g) => (array) $g, $this->sales->byBranch($scope)) : null,
            'tills' => $level === 'business' ? null : array_map(fn (GroupSales $g) => (array) $g, $this->sales->byRegister($filters->shopScope())),
            'products' => array_map(fn (ProductSales $p) => (array) $p, $this->products->top($scope, self::TOP_PRODUCTS)),
            'departments' => self::top($this->products->byDepartment($scope)),
            'categories' => self::top($this->products->byCategory($scope)),
            'staff' => array_map(fn (StaffSales $s) => (array) $s, $this->staff->byUser($scope)),
            'operations' => [
                'lowStock' => $this->operations->lowStock($scope),
                'cash' => $this->operations->cashVariance($scope),
                'ordersReady' => $this->operations->ordersReady($scope),
            ],
        ];
    }

    /**
     * The biggest groups, the rest summed as "Everything else" so the list still adds up to the total.
     *
     * @param  list<LineGroupSales>  $groups
     * @return list<array<string, string|null>>
     */
    private static function top(array $groups): array
    {
        $rows = array_map(fn (LineGroupSales $g) => (array) $g, array_slice($groups, 0, self::TOP_GROUPS));
        $rest = array_slice($groups, self::TOP_GROUPS);

        if ($rest !== []) {
            $sum = fn (string $key, int $scale) => array_reduce($rest, fn (string $carry, LineGroupSales $g) => bcadd($carry, $g->{$key}, $scale), '0');
            $rows[] = ['id' => 'rest', 'name' => 'Everything else ('.count($rest).')', 'qty' => $sum('qty', 4), 'net' => $sum('net', 2), 'gross' => $sum('gross', 2)];
        }

        return $rows;
    }
}
