<?php

namespace App\Domain\Anomalies\Detectors;

use App\Domain\Anomalies\Data\AnomalyFinding;
use App\Domain\Anomalies\Data\DetectionWindow;
use App\Domain\Anomalies\Enums\AnomalyKind;
use App\Domain\Anomalies\Queries\StaffDays;
use App\Domain\Anomalies\Support\AnomalyLinks as L;
use App\Domain\Anomalies\Support\Fmt;
use App\Domain\Anomalies\Support\RobustStats as R;
use App\Domain\Cash\Support\CashLookup;

/**
 * Loss prevention per staff member (module 6.6, daily, yesterday): voids, refunds, no-sale drawer opens and manual
 * discounts per 100 sales served, against (a) the rest of the shop's team over the last 8 weeks (their staff-days with
 * at least {@see self::MIN_TXN} sales; at least {@see self::MIN_PEER_DAYS}) and (b) the person's own last 8 weeks (at
 * least {@see self::MIN_OWN_DAYS} working days). Flagged when far above either (RobustStats::farAbove: 3.5 robust
 * spreads and twice the median); both → one level more severe. Minimum volumes per measure keep a quiet day quiet.
 */
final class StaffExceptions implements Detector
{
    public const MIN_TXN = 10;

    public const MIN_PEER_DAYS = 10;

    public const MIN_OWN_DAYS = 8;

    /**
     * kind, count key, amount key, minimum count (or pounds for discounts), floor per 100 sales, words.
     *
     * @var array<string, array{kind: AnomalyKind, count: string|null, amount: string|null, min: float, floor: float, noun: string, nouns: string, verb: string}>
     */
    private const MEASURES = [
        'voids' => ['kind' => AnomalyKind::StaffVoids, 'count' => 'voids', 'amount' => 'voidAmount', 'min' => 3.0, 'floor' => 1.0, 'noun' => 'void', 'nouns' => 'voids', 'verb' => 'voided'],
        'refunds' => ['kind' => AnomalyKind::StaffRefunds, 'count' => 'refunds', 'amount' => 'refundAmount', 'min' => 3.0, 'floor' => 1.0, 'noun' => 'refund', 'nouns' => 'refunds', 'verb' => 'refunded'],
        'noSales' => ['kind' => AnomalyKind::StaffNoSales, 'count' => 'noSales', 'amount' => null, 'min' => 5.0, 'floor' => 2.0, 'noun' => 'no-sale', 'nouns' => 'no-sales', 'verb' => 'opened the drawer with no sale'],
        'discounts' => ['kind' => AnomalyKind::StaffDiscounts, 'count' => null, 'amount' => 'discount', 'min' => 15.0, 'floor' => 5.0, 'noun' => 'manual discount', 'nouns' => 'manual discounts', 'verb' => 'gave in manual discounts'],
    ];

    public function runsIn(string $mode): bool
    {
        return $mode === DetectionWindow::DAILY;
    }

    public function detect(DetectionWindow $window): array
    {
        $out = [];

        foreach ($window->shops as $branchId => $shop) {
            $days = StaffDays::for($window->companyId, $branchId, $window->baselineFrom(), $window->day);
            $names = null;

            foreach ($days as $user => $byDay) {
                $today = $byDay[$window->day] ?? null;

                if ($today === null || $user === '') {
                    continue;
                }

                foreach (self::MEASURES as $key => $m) {
                    $finding = $this->measure($window, (string) $branchId, $shop, (string) $user, $key, $m, $days, $names);

                    if ($finding !== null) {
                        $out[] = $finding;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param  array{kind: AnomalyKind, count: string|null, amount: string|null, min: float, floor: float, noun: string, nouns: string, verb: string}  $m
     * @param  array<string, array<string, array<string, int|string>>>  $days
     * @param  array<string, string>|null  $names
     */
    private function measure(DetectionWindow $w, string $branchId, string $shop, string $user, string $key, array $m, array $days, ?array &$names): ?AnomalyFinding
    {
        $today = $days[$user][$w->day];
        $count = $m['count'] !== null ? (int) $today[$m['count']] : 0;
        $amount = $m['amount'] !== null ? (string) $today[$m['amount']] : '0.00';
        $volume = $m['count'] !== null ? (float) $count : (float) $amount;

        if ($volume < $m['min']) {
            return null;
        }

        $x = self::rate($volume, (int) $today['txn']);
        $peers = [];
        $own = [];

        foreach ($days as $other => $byDay) {
            foreach ($byDay as $day => $d) {
                if ((int) $d['txn'] < self::MIN_TXN) {
                    continue;
                }

                $rate = self::rate($m['count'] !== null ? (float) $d[$m['count']] : (float) $d[$m['amount']], (int) $d['txn']);

                if ($other !== $user) {
                    $peers[] = $rate;
                } elseif ($day < $w->day) {
                    $own[] = $rate;
                }
            }
        }

        $peerHit = count($peers) >= self::MIN_PEER_DAYS && R::farAbove($x, $peers, $m['floor']);
        $ownHit = count($own) >= self::MIN_OWN_DAYS && R::farAbove($x, $own, $m['floor']);

        if (! $peerHit && ! $ownHit) {
            return null;
        }

        $scores = array_filter([$peerHit ? R::score($x, $peers, $m['floor']) : null, $ownHit ? R::score($x, $own, $m['floor']) : null], fn ($s) => $s !== null);
        $base = $peerHit ? $peers : $own;
        $severity = R::severity(max($scores), R::ratio($x, R::median($base), $m['floor']));
        $severity = $peerHit && $ownHit ? $severity->bump() : $severity;

        $names ??= CashLookup::staff(array_keys($days));
        $name = $names[$user] ?? 'Unknown staff member';
        $what = $m['count'] !== null
            ? Fmt::count($count, $m['noun'], $m['nouns']).($m['amount'] !== null ? ' ('.Fmt::money($amount).')' : '')
            : Fmt::money($amount).' in '.$m['nouns'];
        $unit = $m['count'] !== null ? ucfirst($m['nouns']).' per 100 sales' : 'Manual discount per 100 sales';
        $fmt = fn (float $v) => $m['count'] !== null ? Fmt::rate($v) : Fmt::pounds($v);
        [$start, $end] = $w->utcWindow();

        $summary = $name.' at '.$shop.' '.($m['count'] !== null ? 'had '.$what : $m['verb'].' '.Fmt::money($amount))
            .' on '.$w->dayLabel().': '.$fmt($x).' per 100 sales, against '
            .implode(' and ', array_filter([
                count($own) >= self::MIN_OWN_DAYS ? 'a usual '.$fmt(R::median($own)).' for them' : null,
                count($peers) >= self::MIN_PEER_DAYS ? $fmt(R::median($peers)).' for the rest of the team' : null,
            ])).' over the last 8 weeks.';

        return new AnomalyFinding(
            kind: $m['kind'],
            severity: $severity,
            branchId: $branchId,
            day: $w->day,
            periodStart: $start,
            periodEnd: $end,
            title: $name.': '.$what.' at '.$shop.' on '.$w->dayLabel(),
            summary: $summary,
            facts: [
                ['label' => ucfirst($m['nouns']).' on '.$w->dayLabel(), 'value' => $m['count'] !== null ? (string) $count : Fmt::money($amount)],
                ...($m['amount'] !== null && $m['count'] !== null ? [['label' => 'Value', 'value' => Fmt::money($amount)]] : []),
                ['label' => 'Sales served', 'value' => (string) $today['txn']],
                [
                    'label' => $unit,
                    'value' => $fmt($x),
                    'usual' => count($own) >= self::MIN_OWN_DAYS ? $fmt(R::median($own)) : null,
                    'peers' => count($peers) >= self::MIN_PEER_DAYS ? $fmt(R::median($peers)) : null,
                ],
                ['label' => 'Days compared', 'value' => count($own).' of theirs, '.count($peers).' of the team\'s'],
            ],
            links: [
                match ($key) {
                    'voids' => L::sales('Their voided sales that day', $branchId, $w->day, $w->day, $user, 'voided'),
                    'refunds' => L::sales('Their refunds that day', $branchId, $w->day, $w->day, $user, 'refunds'),
                    'noSales' => L::exceptions('Their no-sales that day', $branchId, $w->day, $w->day, $user, 'NoSale'),
                    default => L::sales('Their sales that day', $branchId, $w->day, $w->day, $user),
                },
                L::exceptions('Their exceptions, last 8 weeks', $branchId, $w->baselineFrom(), $w->day, $user),
                L::staff($user),
            ],
            score: round(max($scores), 2),
            subjectId: $user,
            subjectName: $name,
        );
    }

    /** Per 100 sales served (at least {@see self::MIN_TXN} sales assumed, so a quiet day cannot divide by almost nothing). */
    private static function rate(float $value, int $txn): float
    {
        return $value * 100 / max($txn, self::MIN_TXN);
    }
}
