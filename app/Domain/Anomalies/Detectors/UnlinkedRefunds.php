<?php

namespace App\Domain\Anomalies\Detectors;

use App\Domain\Anomalies\Data\AnomalyFinding;
use App\Domain\Anomalies\Data\DetectionWindow;
use App\Domain\Anomalies\Enums\AnomalyKind;
use App\Domain\Anomalies\Queries\ShopDays;
use App\Domain\Anomalies\Support\AnomalyLinks as L;
use App\Domain\Anomalies\Support\Fmt;
use App\Domain\Anomalies\Support\RobustStats as R;

/**
 * Refunds with no original sale (module 6.6, daily): completed refunds whose `originalSaleId` is blank, the day's
 * value against each trading day of the last 8 weeks (at least {@see self::MIN_DAYS}). Flagged at £{@see self::MIN_VALUE}
 * or more over at least two refunds (or one of £{@see self::MIN_SINGLE}+) and RobustStats::farAbove (spread at least
 * £10). A shop that often refunds without a receipt sets its own, higher, normal.
 */
final class UnlinkedRefunds implements Detector
{
    public const MIN_DAYS = 14;

    public const MIN_VALUE = 30.0;

    public const MIN_SINGLE = 75.0;

    public function runsIn(string $mode): bool
    {
        return $mode === DetectionWindow::DAILY;
    }

    public function detect(DetectionWindow $window): array
    {
        $out = [];

        foreach ($window->shops as $branchId => $shop) {
            $branchId = (string) $branchId;
            $refunds = ShopDays::unlinkedRefunds($window->companyId, $branchId, $window->baselineFrom(), $window->day);
            $today = $refunds[$window->day] ?? null;

            if ($today === null) {
                continue;
            }

            $txn = ShopDays::transactions($window->companyId, $branchId, $window->baselineFrom(), $window->baselineTo());
            $days = array_values(array_filter(array_keys($txn), fn (string $d) => $txn[$d] > 0));
            $base = array_map(fn (string $d) => (float) ($refunds[$d]['amount'] ?? 0), $days);
            $x = (float) $today['amount'];
            $enough = $x >= self::MIN_VALUE && ($today['count'] >= 2 || $x >= self::MIN_SINGLE);

            if (count($days) < self::MIN_DAYS || ! $enough || ! R::farAbove($x, $base, 10.0)) {
                continue;
            }

            $score = R::score($x, $base, 10.0);
            $usualCount = R::median(array_map(fn (string $d) => (float) ($refunds[$d]['count'] ?? 0), $days));
            [$start, $end] = $window->utcWindow();

            $out[] = new AnomalyFinding(
                kind: AnomalyKind::UnlinkedRefunds,
                severity: R::severity($score, R::ratio($x, R::median($base), 10.0)),
                branchId: $branchId,
                day: $window->day,
                periodStart: $start,
                periodEnd: $end,
                title: $shop.': '.Fmt::money($today['amount']).' refunded without the original sale on '.$window->dayLabel(),
                summary: $shop.' made '.Fmt::count($today['count'], 'refund').' with no original sale on '.$window->dayLabel().', '
                    .Fmt::money($today['amount']).' in all (largest '.Fmt::money($today['largest']).'), against a usual '
                    .Fmt::pounds(R::median($base)).' a day over the last 8 weeks.',
                facts: [
                    ['label' => 'Refunds without the original sale', 'value' => (string) $today['count'], 'usual' => Fmt::rate($usualCount)],
                    ['label' => 'Value', 'value' => Fmt::money($today['amount']), 'usual' => Fmt::pounds(R::median($base))],
                    ['label' => 'Largest refund', 'value' => Fmt::money($today['largest'])],
                    ['label' => 'Trading days compared', 'value' => (string) count($days)],
                ],
                links: [
                    L::sales('Refunds that day', $branchId, $window->day, $window->day, null, 'refunds'),
                ],
                score: round($score, 2),
            );
        }

        return $out;
    }
}
