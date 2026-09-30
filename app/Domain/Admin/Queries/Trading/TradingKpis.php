<?php

namespace App\Domain\Admin\Queries\Trading;

use App\Domain\Admin\Data\TradingFilters;
use App\Domain\Reporting\Data\HourSales;
use App\Domain\Reporting\Data\SalesTotals;
use App\Domain\Reporting\Queries\SalesReport;
use App\Domain\Shared\Support\Money;

/**
 * The KPI tiles of the admin trading dashboard (DASHBOARD.md §2.2, summed over the chosen businesses) with their
 * change against the compare window (§2.9). For Today the headline tiles compare up to the same local hour, read
 * from `rpt_sales_hourly`; the other tiles then show no change (a half day against a whole day always looks bad).
 */
final class TradingKpis
{
    public function __construct(private readonly SalesReport $sales) {}

    /**
     * @param  list<float>  $netSeries
     * @param  list<float>  $grossSeries
     * @param  list<int>  $txnSeries
     * @return array<string, mixed>
     */
    public function build(TradingFilters $filters, array $netSeries, array $grossSeries, array $txnSeries): array
    {
        $scope = $filters->scope();
        $compareScope = $filters->compareScope();
        $current = $this->sales->totals($scope);
        $previous = $compareScope === null ? null : $this->sales->totals($compareScope);
        $before = $filters->isToday() && $compareScope !== null
            ? self::fromHour($this->sales->upToHour($compareScope, $filters->hour))
            : $previous;

        $averages = [];

        foreach ($netSeries as $i => $net) {
            $averages[] = ($txnSeries[$i] ?? 0) > 0 ? round($net / $txnSeries[$i], 2) : 0.0;
        }

        $secondary = $filters->isToday() ? null : $previous;

        return [
            'headline' => [
                'gross' => self::figure($current->gross, $before?->gross, $grossSeries),
                'net' => self::figure($current->net, $before?->net, $netSeries),
                'transactions' => self::figure((string) $current->transactions, $before === null ? null : (string) $before->transactions, $txnSeries),
                'averageBasket' => self::figure($current->averageBasketExVat(), $before?->averageBasketExVat(), $averages, $current->averageBasketIncVat()),
            ],
            'totals' => $current->toArray(),
            'previous' => $secondary?->toArray(),
            'changes' => $secondary === null ? null : [
                'vat' => self::change($current->vat, $secondary->vat),
                'refundGross' => self::change($current->refundGross, $secondary->refundGross),
                'discount' => self::change($current->discount, $secondary->discount),
                'takings' => self::change($current->takings, $secondary->takings),
                'voidTotal' => self::change($current->voidTotal, $secondary->voidTotal),
            ],
        ];
    }

    /** (now − before) ÷ before as a percentage with one decimal; null when before is 0 or missing ("—"). */
    public static function change(?string $now, ?string $before): ?string
    {
        if ($now === null || $before === null || Money::isZero($before)) {
            return null;
        }

        return Money::round(bcdiv(bcmul(bcsub(Money::parse($now), Money::parse($before), 12), '100', 12), Money::parse($before), 12), 1);
    }

    /**
     * @param  list<float|int>  $series
     * @return array{value: string|null, previous: string|null, change: string|null, series: list<float|int>, secondary: string|null}
     */
    private static function figure(?string $value, ?string $previous, array $series, ?string $secondary = null): array
    {
        return ['value' => $value, 'previous' => $previous, 'change' => self::change($value, $previous), 'series' => $series, 'secondary' => $secondary];
    }

    /** Totals holding only what `rpt_sales_hourly` has (net, gross, transactions): the Today compare. */
    private static function fromHour(HourSales $h): SalesTotals
    {
        return new SalesTotals($h->gross, $h->net, '0.00', $h->transactions, 0, '0.00', '0.00', '0.00', '0.00', '0.00', '0.0000', '0.00', '0.00', 0, '0.00');
    }
}
