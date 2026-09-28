<?php

namespace App\Domain\Admin\Queries\Dashboard;

use App\Domain\Admin\Enums\DashboardRange;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Shared\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The dashboard's revenue chart: paid invoice totals per London week (12W) or month (6M, 1Y), oldest first,
 * zeros where nothing was paid, and the change against the same length of time just before. One query.
 *
 * @phpstan-import-type Delta from Change
 *
 * @phpstan-type Chart array{range: string, label: string, points: list<array{label: string, value: float}>, total: string, change: Delta|null}
 */
final class RevenueChart
{
    /**
     * @return Chart
     */
    public static function for(DashboardRange $range, CarbonImmutable $now): array
    {
        [$current, $previous] = match ($range) {
            DashboardRange::TwelveWeeks => [Buckets::weeks($now, 12), Buckets::weeks($now, 12, 12)],
            DashboardRange::SixMonths => [Buckets::months($now, 6), Buckets::months($now, 6, 6)],
            DashboardRange::OneYear => [Buckets::months($now, 12), Buckets::months($now, 12, 12)],
        };

        $paid = DashboardRows::paidSince($previous[0]['start']);
        $amounts = array_map(fn (array $bucket) => DashboardRows::paidBetween($paid, $bucket['start'], $bucket['end']), $current);
        $total = Money::sum($amounts);
        $before = DashboardRows::paidBetween($paid, $previous[0]['start'], $current[0]['start']);

        return [
            'range' => $range->value,
            'label' => $range->label(),
            'points' => array_map(fn (array $bucket, string $amount) => ['label' => $bucket['label'], 'value' => (float) $amount], $current, $amounts),
            'total' => BillingFormat::money($total),
            'change' => Change::percent($total, $before, $range->previousLabel()),
        ];
    }
}
