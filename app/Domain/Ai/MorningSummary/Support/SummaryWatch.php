<?php

namespace App\Domain\Ai\MorningSummary\Support;

use App\Domain\Ai\MorningSummary\Data\CompanyFacts;
use App\Domain\Ai\MorningSummary\Data\SummaryAudience;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\Notifications\Enums\AlertType;
use App\Domain\Notifications\Queries\DigestSections;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Enums\Ability;

/**
 * The movers and "needs a look" lines of a morning summary (module 6.3), all computed here from CompanyFacts:
 *
 * - movers: products whose sales (inc VAT) changed most against the same weekday last week, at least £5 either way;
 * - noSales: a shop with no sales yesterday that traded on the same weekday last week (often a till not syncing);
 * - refunds / voids / discounts: yesterday at least £10 and at least twice the shop's usual day (the trading days of
 *   the 28 days before, at least 7 of them);
 * - fast sellers at their low-stock point (StockView), till and sync problems (ShopsView), cash variances (CashView),
 *   compliance due (ComplianceView). The last three only when the user does not already get them in the digest.
 */
final class SummaryWatch
{
    public const MOVERS = 3;

    public const MOVER_MIN = '5.00';

    public const OUTLIER_MIN = '10.00';

    public const OUTLIER_FACTOR = '2';

    public const MIN_BASELINE_DAYS = 7;

    public const CASH_ITEMS = 3;

    /** Fast sellers listed, the fastest of the covered shops first. */
    public const STOCK_ITEMS = 5;

    /**
     * @param  list<string>  $shopIds
     * @return array{up: list<array{name: string, sales: string, before: string, difference: string}>, down: list<array{name: string, sales: string, before: string, difference: string}>}
     */
    public static function movers(CompanyFacts $facts, array $shopIds): array
    {
        $products = [];

        foreach ($shopIds as $shop) {
            foreach ($facts->products[$shop] ?? [] as $id => $p) {
                $entry = $products[$id] ?? ['name' => $p['name'], 'sales' => '0.00', 'before' => '0.00'];
                $entry['sales'] = bcadd($entry['sales'], $p['now'], 2);
                $entry['before'] = bcadd($entry['before'], $p['before'], 2);
                $products[$id] = $entry;
            }
        }

        $all = array_map(fn (array $p) => [...$p, 'difference' => bcsub($p['sales'], $p['before'], 2)], array_values($products));
        $up = array_values(array_filter($all, fn (array $p) => bccomp($p['difference'], self::MOVER_MIN, 2) >= 0));
        $down = array_values(array_filter($all, fn (array $p) => bccomp($p['difference'], '-'.self::MOVER_MIN, 2) <= 0));
        usort($up, fn (array $a, array $b) => bccomp($b['difference'], $a['difference'], 2) ?: strcmp($a['name'], $b['name']));
        usort($down, fn (array $a, array $b) => bccomp($a['difference'], $b['difference'], 2) ?: strcmp($a['name'], $b['name']));

        return ['up' => array_slice($up, 0, self::MOVERS), 'down' => array_slice($down, 0, self::MOVERS)];
    }

    /**
     * @param  array<string, string>  $shops  covered shops
     * @return list<array{kind: string, text: string}>
     */
    public static function items(CompanyFacts $facts, array $shops, SummaryAudience $audience): array
    {
        $out = [];
        $add = function (string $kind, ?string $text) use (&$out): void {
            if ($text !== null) {
                $out[] = ['kind' => $kind, 'text' => $text];
            }
        };

        foreach ($shops as $id => $name) {
            $now = $facts->yesterday[$id] ?? null;
            $week = $facts->lastWeek[$id] ?? null;

            if (($now->transactions ?? 0) === 0 && $week !== null && $week->transactions > 0) {
                $add('noSales', $name.': no sales reached the portal for yesterday; the same day last week took '.MailFormat::money($week->gross));
            }

            if ($now === null || $now->transactions === 0) {
                continue;
            }

            $base = $facts->baseline[$id] ?? null;
            $add('refunds', self::outlier($name, $now->refundGross, $base['refunds'] ?? null, $base['days'] ?? 0, 'refunded', MailFormat::count($now->refundCount, 'refund')));
            $add('voids', self::outlier($name, $now->voidTotal, $base['voids'] ?? null, $base['days'] ?? 0, 'voided', MailFormat::count($now->voidCount, 'void')));
            $add('discounts', self::outlier($name, $now->manualDiscount(), $base['discounts'] ?? null, $base['days'] ?? 0, 'given in manual discounts', null));
        }

        if ($audience->can(Ability::StockView)) {
            $lines = [];

            foreach ($shops as $id => $name) {
                foreach ($facts->fastSellers[$id] ?? [] as $line) {
                    $lines[] = [...$line, 'shop' => $name];
                }
            }

            usort($lines, fn (array $a, array $b) => Money::compare($b['soldWeek'], $a['soldWeek']) ?: strcmp($a['name'], $b['name']));

            foreach (array_slice($lines, 0, self::STOCK_ITEMS) as $line) {
                $add('stock', $line['shop'].': '.$line['name'].(Money::compare($line['onHand'], '0') <= 0
                    ? ' is out of stock ('.$line['onHand'].' on hand), '.$line['soldWeek'].' sold in the last 7 days'
                    : ' is low, '.$line['onHand'].' on hand with '.$line['soldWeek'].' sold in the last 7 days'));
            }
        }

        foreach ($shops as $id => $name) {
            foreach ($facts->tills[$id] ?? [] as $problem) {
                $type = AlertType::from($problem['type']);

                if ($audience->can($type->ability()) && ! $audience->inDigest($type)) {
                    $add('tills', $problem['text']);
                }
            }
        }

        if ($audience->can(Ability::CashView) && ! $audience->inDigest(AlertType::CashVariance)) {
            $cash = array_merge(...array_values(array_map(fn ($entry) => $entry['items'], array_intersect_key($facts->cash, $shops))));

            foreach (array_slice($cash, 0, self::CASH_ITEMS) as $item) {
                $add('cash', 'Cash difference: '.$item);
            }

            if (count($cash) > self::CASH_ITEMS) {
                $add('cash', 'and '.MailFormat::count(count($cash) - self::CASH_ITEMS, 'more cash difference', 'more cash differences').' over your alert amount');
            }
        }

        if ($audience->can(Ability::ComplianceView) && ! $audience->inDigest(AlertType::Compliance)) {
            $counts = [];

            foreach (array_intersect_key($facts->compliance, [...$shops, '*' => '']) as $entry) {
                foreach ($entry['counts'] as $key => $n) {
                    $counts[$key] = ($counts[$key] ?? 0) + $n;
                }
            }

            if (array_sum($counts) > 0) {
                $add('compliance', 'Compliance: '.DigestSections::summary(AlertType::Compliance, $counts));
            }
        }

        return $out;
    }

    private static function outlier(string $shop, string $value, ?string $baseSum, int $days, string $verb, ?string $count): ?string
    {
        if ($baseSum === null || $days < self::MIN_BASELINE_DAYS || Money::compare($value, self::OUTLIER_MIN) < 0) {
            return null;
        }

        $usual = Money::round(bcdiv($baseSum, (string) $days, 6));

        if (Money::compare($value, bcmul($usual, self::OUTLIER_FACTOR, 2)) < 0) {
            return null;
        }

        return $shop.': '.MailFormat::money($value).' '.$verb.($count !== null ? ' ('.$count.')' : '').' yesterday, against a usual '.MailFormat::money($usual).' a day';
    }
}
