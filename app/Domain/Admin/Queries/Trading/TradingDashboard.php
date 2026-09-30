<?php

namespace App\Domain\Admin\Queries\Trading;

use App\Domain\Admin\Data\TradingFilters;
use App\Domain\Reporting\Dashboard\DashboardKpis;
use App\Domain\Reporting\Dashboard\DashboardSeries;
use App\Domain\Reporting\Data\GroupSales;
use App\Domain\Reporting\Data\LeaderSales;
use App\Domain\Reporting\Data\ProductSales;
use App\Domain\Reporting\Data\TenderTotals;
use App\Domain\Reporting\Data\VatRateTotals;
use App\Domain\Reporting\Queries\AdminTradingReport;
use App\Domain\Reporting\Queries\ProductReport;
use App\Domain\Reporting\Queries\SalesReport;
use App\Domain\Reporting\Queries\TenderReport;
use App\Domain\Reporting\Queries\VatReport;
use App\Domain\Shared\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The admin trading dashboard (module 3.2): the Business panel's tiles and charts (DASHBOARD.md §2.2–2.3) summed
 * over every business ("Sales across all businesses", §3), or one business, or one shop of it:
 *
 *     $dashboard->for(TradingFilters::resolve(TradingPeriod::Last7Days, null, null, TradingCompare::PreviousPeriod));
 *
 * Reads only the `rpt_*` tables through the 3.1 read side (never raw sales), about 14 grouped queries whatever
 * the number of businesses, cached for CACHE_SECONDS per filter set. What a viewer may see is decided by the
 * route (`trading.view`), so the cache holds no per-admin data.
 */
final class TradingDashboard
{
    public const CACHE_SECONDS = 60;

    public const LEADERS = 10;

    public function __construct(
        private readonly SalesReport $sales,
        private readonly TenderReport $tenders,
        private readonly VatReport $vat,
        private readonly ProductReport $products,
        private readonly AdminTradingReport $admin,
        private readonly DashboardKpis $kpis,
        private readonly DashboardSeries $series,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(TradingFilters $filters): array
    {
        /** @var array<string, mixed> */
        return Cache::remember($filters->cacheKey(), self::CACHE_SECONDS, fn () => $this->compute($filters, CarbonImmutable::now()));
    }

    /**
     * @return array<string, mixed>
     */
    public function compute(TradingFilters $filters, CarbonImmutable $now): array
    {
        $scope = $filters->scope();
        $compare = $filters->compareScope();
        $daily = $this->series->daily($filters);
        $hourly = $this->series->hourly($filters);
        $spark = DashboardSeries::sparklines($daily, $hourly);
        $activity = $this->admin->activity($scope);

        return [
            'generatedAt' => $now->utc()->format('Y-m-d\TH:i:s\Z'),
            'level' => $filters->level(),
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
            'activity' => (array) $activity,
            'kpis' => $this->kpis->build($filters, $spark['net'], $spark['gross'], $spark['transactions']),
            'daily' => $daily,
            'hourly' => $hourly,
            'tenders' => $this->tenderMix($filters),
            'vat' => $this->vatRates($filters),
            'leaders' => $this->leaders($filters),
        ];
    }

    /**
     * All businesses: the top businesses and shops. One business: its shops and top products. One shop: its tills
     * and top products (products are per business, so never across businesses).
     *
     * @return array<string, list<array<string, mixed>>|null>
     */
    private function leaders(TradingFilters $filters): array
    {
        $scope = $filters->scope();
        $leaders = fn (array $rows) => array_map(fn (LeaderSales $l) => (array) $l, $rows);

        return match ($filters->level()) {
            'all' => [
                'businesses' => $leaders($this->admin->topCompanies($scope, self::LEADERS)),
                'shops' => $leaders($this->admin->topBranches($scope, self::LEADERS)),
                'tills' => null,
                'products' => null,
            ],
            'business' => [
                'businesses' => null,
                'shops' => $leaders($this->admin->topBranches($scope, 100)),
                'tills' => null,
                'products' => $this->topProducts($filters),
            ],
            default => [
                'businesses' => null,
                'shops' => null,
                'tills' => array_map(fn (GroupSales $g) => (array) $g, $this->sales->byRegister($scope)),
                'products' => $this->topProducts($filters),
            ],
        };
    }

    /**
     * Takings by payment type. Across businesses every business has its own "Card" and "Cash" types, so they are
     * added up by name there; one business keeps its types apart.
     *
     * @return list<array<string, mixed>>
     */
    private function tenderMix(TradingFilters $filters): array
    {
        $rows = array_map(fn (TenderTotals $t) => (array) $t, $this->tenders->byPaymentType($filters->scope()));

        if ($filters->level() !== 'all') {
            return $rows;
        }

        $merged = [];

        foreach ($rows as $row) {
            $key = mb_strtolower(trim((string) $row['name']));
            $merged[$key] = isset($merged[$key]) ? [
                ...$merged[$key],
                'amount' => Money::add($merged[$key]['amount'], $row['amount']),
                'payments' => $merged[$key]['payments'] + $row['payments'],
                'refunds' => Money::add($merged[$key]['refunds'], $row['refunds']),
            ] : [...$row, 'paymentTypeId' => $key];
        }

        usort($merged, fn (array $a, array $b) => Money::compare($b['amount'], $a['amount']));

        return $merged;
    }

    /**
     * VAT by rate; across businesses the same code and percentage are one line (each business has its own rate ids).
     *
     * @return list<array<string, mixed>>
     */
    private function vatRates(TradingFilters $filters): array
    {
        $rows = array_map(fn (VatRateTotals $v) => (array) $v, $this->vat->byRate($filters->scope()));

        if ($filters->level() !== 'all') {
            return $rows;
        }

        $merged = [];

        foreach ($rows as $row) {
            $key = $row['code'].'|'.$row['percentage'];
            $merged[$key] = isset($merged[$key]) ? [
                ...$merged[$key],
                'net' => Money::add($merged[$key]['net'], $row['net']),
                'vat' => Money::add($merged[$key]['vat'], $row['vat']),
                'gross' => Money::add($merged[$key]['gross'], $row['gross']),
            ] : [...$row, 'vatRateId' => $key];
        }

        return array_values($merged);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function topProducts(TradingFilters $filters): array
    {
        return array_map(fn (ProductSales $p) => (array) $p, $this->products->top($filters->scope(), self::LEADERS));
    }
}
