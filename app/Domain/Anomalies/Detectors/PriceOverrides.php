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
 * A spike in price overrides or manual discounts at a shop (module 6.6, daily): per 100 sales served, the day against
 * the shop's trading days of the last 8 weeks (at least {@see self::MIN_DAYS}). Price overrides need at least
 * {@see self::MIN_OVERRIDES} that day, manual discounts at least £{@see self::MIN_DISCOUNT}; then RobustStats::farAbove.
 * One finding per shop and day with both measures; staff-level discounts are StaffExceptions' job.
 */
final class PriceOverrides implements Detector
{
    public const MIN_DAYS = 14;

    public const MIN_OVERRIDES = 5;

    public const MIN_DISCOUNT = 20.0;

    public function runsIn(string $mode): bool
    {
        return $mode === DetectionWindow::DAILY;
    }

    public function detect(DetectionWindow $window): array
    {
        $out = [];

        foreach ($window->shops as $branchId => $shop) {
            $branchId = (string) $branchId;
            $args = [$window->companyId, $branchId, $window->baselineFrom(), $window->day];
            $txn = ShopDays::transactions(...$args);
            $overrides = ShopDays::priceOverrides(...$args);
            $discounts = ShopDays::manualDiscounts(...$args);
            $baseDays = array_values(array_filter(array_keys($txn), fn (string $d) => $d < $window->day && $txn[$d] > 0));

            if (count($baseDays) < self::MIN_DAYS || ($txn[$window->day] ?? 0) === 0) {
                continue;
            }

            $per100 = fn (float $v, string $d) => $v * 100 / max($txn[$d] ?? 0, 10);
            $o = (float) ($overrides[$window->day] ?? 0);
            $dsc = (float) ($discounts[$window->day] ?? 0);
            $oBase = array_map(fn (string $d) => $per100((float) ($overrides[$d] ?? 0), $d), $baseDays);
            $dBase = array_map(fn (string $d) => $per100((float) ($discounts[$d] ?? 0), $d), $baseDays);
            $oX = $per100($o, $window->day);
            $dX = $per100($dsc, $window->day);
            $oHit = $o >= self::MIN_OVERRIDES && R::farAbove($oX, $oBase, 1.0);
            $dHit = $dsc >= self::MIN_DISCOUNT && R::farAbove($dX, $dBase, 5.0);

            if (! $oHit && ! $dHit) {
                continue;
            }

            $scores = array_filter([$oHit ? R::score($oX, $oBase, 1.0) : null, $dHit ? R::score($dX, $dBase, 5.0) : null], fn ($s) => $s !== null);
            $ratio = max($oHit ? R::ratio($oX, R::median($oBase), 1.0) : 0, $dHit ? R::ratio($dX, R::median($dBase), 5.0) : 0);
            $severity = R::severity(max($scores), $ratio);
            $severity = $oHit && $dHit ? $severity->bump() : $severity;
            $amount = $discounts[$window->day] ?? '0.00';
            $what = implode(' and ', array_filter([
                $oHit ? Fmt::count((int) $o, 'price override') : null,
                $dHit ? Fmt::money($amount).' in manual discounts' : null,
            ]));
            [$start, $end] = $window->utcWindow();

            $out[] = new AnomalyFinding(
                kind: AnomalyKind::PriceOverrides,
                severity: $severity,
                branchId: $branchId,
                day: $window->day,
                periodStart: $start,
                periodEnd: $end,
                title: $shop.': '.$what.' on '.$window->dayLabel(),
                summary: $shop.' logged '.$what.' on '.$window->dayLabel().' over '.Fmt::count($txn[$window->day], 'sale')
                    .', well above its usual day over the last 8 weeks. Worth checking who changed prices and why.',
                facts: [
                    ['label' => 'Price overrides', 'value' => (string) (int) $o],
                    ['label' => 'Price overrides per 100 sales', 'value' => Fmt::rate($oX), 'usual' => Fmt::rate(R::median($oBase))],
                    ['label' => 'Manual discounts', 'value' => Fmt::money($amount)],
                    ['label' => 'Manual discount per 100 sales', 'value' => Fmt::pounds($dX), 'usual' => Fmt::pounds(R::median($dBase))],
                    ['label' => 'Sales served', 'value' => (string) $txn[$window->day]],
                    ['label' => 'Trading days compared', 'value' => (string) count($baseDays)],
                ],
                links: [
                    L::exceptions('Price overrides that day', $branchId, $window->day, $window->day, null, 'PriceOverride'),
                    L::sales('Sales that day', $branchId, $window->day, $window->day),
                ],
                score: round(max($scores), 2),
            );
        }

        return $out;
    }
}
