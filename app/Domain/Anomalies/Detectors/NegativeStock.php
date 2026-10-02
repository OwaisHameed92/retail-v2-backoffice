<?php

namespace App\Domain\Anomalies\Detectors;

use App\Domain\Anomalies\Data\AnomalyFinding;
use App\Domain\Anomalies\Data\DetectionWindow;
use App\Domain\Anomalies\Enums\AnomalyKind;
use App\Domain\Anomalies\Queries\ShopDays;
use App\Domain\Anomalies\Support\AnomalyLinks as L;
use App\Domain\Anomalies\Support\Fmt;
use App\Domain\Anomalies\Support\RobustStats as R;
use App\Domain\Reporting\Support\TradingDay;
use Illuminate\Support\Facades\DB;

/**
 * A spike of products going below zero at a shop (module 6.6, daily): distinct products whose stock movement took
 * them from zero or more to below zero (`StockMovement.qtyBefore` ≥ 0, `qtyAfter` < 0) on the day, against each day of
 * the last 8 weeks (at least {@see self::MIN_DAYS} trading days). At least {@see self::MIN_PRODUCTS}
 * products and RobustStats::farAbove. Often a delivery not booked in, or stock sold under the wrong product.
 */
final class NegativeStock implements Detector
{
    public const MIN_DAYS = 14;

    public const MIN_PRODUCTS = 5;

    public function runsIn(string $mode): bool
    {
        return $mode === DetectionWindow::DAILY;
    }

    public function detect(DetectionWindow $window): array
    {
        [$start] = TradingDay::window($window->baselineFrom());
        [$dayStart, $end] = $window->utcWindow();
        $rows = DB::table('stock_movements')->where('company_id', $window->companyId)->whereIn('branch_id', array_keys($window->shops))
            ->whereNull('deleted_at')->where('at', '>=', $start->format('Y-m-d H:i:s'))->where('at', '<', $end->format('Y-m-d H:i:s'))
            ->where('qty_after', '<', 0)->where(fn ($q) => $q->whereNull('qty_before')->orWhere('qty_before', '>=', 0))
            ->get(['branch_id', 'product_id', 'at']);
        $negative = [];

        foreach ($rows as $r) {
            [$day] = TradingDay::of((string) $r->at);
            $negative[(string) $r->branch_id][$day][(string) $r->product_id] = true;
        }

        $out = [];

        foreach ($window->shops as $branchId => $shop) {
            $branchId = (string) $branchId;
            $txn = ShopDays::transactions($window->companyId, $branchId, $window->baselineFrom(), $window->baselineTo());
            $days = array_values(array_filter(array_keys($txn), fn (string $d) => $txn[$d] > 0));
            $today = $negative[$branchId][$window->day] ?? [];
            $x = (float) count($today);
            $base = array_map(fn (string $d) => (float) count($negative[$branchId][$d] ?? []), $days);

            if (count($days) < self::MIN_DAYS || $x < self::MIN_PRODUCTS || ! R::farAbove($x, $base, 1.0)) {
                continue;
            }

            $score = R::score($x, $base, 1.0);
            $names = DB::table('products')->where('company_id', $window->companyId)->whereIn('id', array_keys($today))->orderBy('name')->limit(5)->pluck('name')->all();

            $out[] = new AnomalyFinding(
                kind: AnomalyKind::NegativeStock,
                severity: R::severity($score, R::ratio($x, R::median($base), 1.0)),
                branchId: $branchId,
                day: $window->day,
                periodStart: $dayStart,
                periodEnd: $end,
                title: $shop.': '.Fmt::count((int) $x, 'product').' went below zero on '.$window->dayLabel(),
                summary: Fmt::count((int) $x, 'product').' at '.$shop.' went below zero on '.$window->dayLabel().', against a usual '
                    .Fmt::rate(R::median($base)).' a day over the last 8 weeks. Often a delivery not booked in, or items sold under the wrong product.',
                facts: [
                    ['label' => 'Products going below zero', 'value' => (string) (int) $x, 'usual' => Fmt::rate(R::median($base))],
                    ['label' => 'Days compared', 'value' => (string) count($days)],
                    ['label' => 'For example', 'value' => $names === [] ? 'Unknown products' : implode(', ', array_map('strval', $names))],
                ],
                links: [
                    L::link('Products below zero', '/app/stock', ['shop' => $branchId, 'status' => 'negative']),
                ],
                score: round($score, 2),
            );
        }

        return $out;
    }
}
