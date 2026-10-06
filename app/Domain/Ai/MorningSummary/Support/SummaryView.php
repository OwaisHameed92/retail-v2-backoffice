<?php

namespace App\Domain\Ai\MorningSummary\Support;

use App\Domain\Ai\MorningSummary\Data\CompanyFacts;
use App\Domain\Ai\MorningSummary\Data\SummaryAudience;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\Reporting\Dashboard\DashboardKpis;
use App\Domain\Reporting\Data\SalesTotals;
use App\Domain\Shared\Country\Country;
use Carbon\CarbonImmutable;

/**
 * Cuts a business's morning facts (CompanyFacts) down to one audience (module 6.3): their shops, yesterday's sales
 * (inc VAT) against the same weekday last week and last year (when that day had sales), top movers, and the things
 * to watch ({@see SummaryWatch}). The email, the dashboard card and the model's facts all come from this array.
 *
 * @phpstan-type Row array{id: string, name: string, sales: string, transactions: int, lastWeek: string|null, lastWeekChange: string|null, lastYear: string|null, lastYearChange: string|null}
 * @phpstan-type View array{day: string, dayLabel: string, scope: string, total: array<string, mixed>, shops: list<Row>, moversUp: list<array<string, string>>, moversDown: list<array<string, string>>, watch: list<array{kind: string, text: string}>}
 */
final class SummaryView
{
    /**
     * @return View|null null when the audience covers none of the business's shops
     */
    public static function build(CompanyFacts $facts, SummaryAudience $audience): ?array
    {
        $shops = array_filter($facts->shops, fn ($name, $id) => $audience->covers((string) $id), ARRAY_FILTER_USE_BOTH);

        if ($shops === []) {
            return null;
        }

        $rows = [];
        $sum = ['sales' => '0.00', 'txn' => 0, 'week' => '0.00', 'weekTxn' => 0, 'year' => '0.00', 'yearTxn' => 0];

        foreach ($shops as $id => $name) {
            $now = $facts->yesterday[$id] ?? null;
            $week = self::traded($facts->lastWeek[$id] ?? null);
            $year = self::traded($facts->lastYear[$id] ?? null);
            $sales = $now->gross ?? '0.00';
            $rows[] = self::row((string) $id, $name, $sales, $now->transactions ?? 0, $week?->gross, $year?->gross);
            $sum['sales'] = bcadd($sum['sales'], $sales, 2);
            $sum['txn'] += $now->transactions ?? 0;
            $sum['week'] = bcadd($sum['week'], $week->gross ?? '0', 2);
            $sum['weekTxn'] += $week->transactions ?? 0;
            $sum['year'] = bcadd($sum['year'], $year->gross ?? '0', 2);
            $sum['yearTxn'] += $year->transactions ?? 0;
        }

        usort($rows, fn (array $a, array $b) => bccomp($b['sales'], $a['sales'], 2) ?: strcmp($a['name'], $b['name']));
        $total = self::row('', '', $sum['sales'], $sum['txn'], $sum['weekTxn'] > 0 ? $sum['week'] : null, $sum['yearTxn'] > 0 ? $sum['year'] : null);
        $movers = $sum['txn'] > 0 && $sum['weekTxn'] > 0 ? SummaryWatch::movers($facts, array_keys($shops)) : ['up' => [], 'down' => []];

        return [
            'day' => $facts->day,
            'dayLabel' => CarbonImmutable::parse($facts->day, Country::zone())->format('l j F Y'),
            'scope' => self::scope($facts, $shops, $audience),
            'total' => [...array_diff_key($total, ['id' => 1, 'name' => 1]), 'average' => SalesTotals::average($sum['sales'], $sum['txn'])],
            'shops' => $rows,
            'moversUp' => $movers['up'],
            'moversDown' => $movers['down'],
            'watch' => SummaryWatch::items($facts, $shops, $audience),
        ];
    }

    /**
     * Worth a summary: sales yesterday or on the same weekday last week (a drop to nothing is news), or something to
     * watch. A business with neither gets no summary and no model call.
     *
     * @param  View  $view
     */
    public static function hasNews(array $view): bool
    {
        return $view['total']['transactions'] > 0 || $view['total']['lastWeek'] !== null || $view['watch'] !== [];
    }

    /**
     * The facts the model may use, as words and formatted figures. Every number in the narrative must appear here.
     *
     * @param  View  $view
     * @return array<string, mixed>
     */
    public static function forModel(array $view): array
    {
        $t = $view['total'];
        $mover = fn (array $m) => ['product' => $m['name'], 'yesterday' => MailFormat::money($m['sales']), 'same day last week' => MailFormat::money($m['before']), 'change' => self::signedMoney($m['difference'])];

        return array_filter([
            'day' => $view['dayLabel'],
            'shops covered' => $view['scope'],
            Country::tax('sales yesterday (inc VAT)') => MailFormat::money($t['sales']),
            'transactions yesterday' => $t['transactions'],
            'average basket yesterday' => $t['average'] !== null ? MailFormat::money($t['average']) : null,
            'sales on the same weekday last week' => $t['lastWeek'] !== null ? MailFormat::money($t['lastWeek']) : 'no sales recorded',
            'change on last week' => self::percent($t['lastWeekChange']),
            'sales on the same weekday last year' => $t['lastYear'] !== null ? MailFormat::money($t['lastYear']) : 'not available',
            'change on last year' => self::percent($t['lastYearChange']),
            'by shop' => count($view['shops']) > 1 ? array_map(fn (array $r) => array_filter([
                'shop' => $r['name'],
                'sales yesterday' => MailFormat::money($r['sales']),
                'transactions' => $r['transactions'],
                'change on last week' => self::percent($r['lastWeekChange']),
                'change on last year' => self::percent($r['lastYearChange']),
            ], fn ($v) => $v !== null), $view['shops']) : null,
            'biggest risers against last week' => array_map($mover, $view['moversUp']) ?: null,
            'biggest fallers against last week' => array_map($mover, $view['moversDown']) ?: null,
            'needs a look' => array_column($view['watch'], 'text') ?: null,
        ], fn ($v) => $v !== null);
    }

    /** "12.5" → "+12.5%", "-3" → "-3%". */
    public static function percent(?string $change): ?string
    {
        if ($change === null) {
            return null;
        }

        return (str_starts_with($change, '-') ? '' : '+').$change.'%';
    }

    public static function signedMoney(string $value): string
    {
        return (str_starts_with($value, '-') ? '-' : '+').MailFormat::money(ltrim($value, '-'));
    }

    /**
     * @return Row
     */
    private static function row(string $id, string $name, string $sales, int $txn, ?string $week, ?string $year): array
    {
        return [
            'id' => $id, 'name' => $name, 'sales' => $sales, 'transactions' => $txn,
            'lastWeek' => $week, 'lastWeekChange' => DashboardKpis::change($sales, $week),
            'lastYear' => $year, 'lastYearChange' => DashboardKpis::change($sales, $year),
        ];
    }

    /** A compare day counts only when it had sales ("if available"). */
    private static function traded(?SalesTotals $totals): ?SalesTotals
    {
        return $totals !== null && $totals->transactions > 0 ? $totals : null;
    }

    /**
     * @param  array<string, string>  $shops  covered shops
     */
    private static function scope(CompanyFacts $facts, array $shops, SummaryAudience $audience): string
    {
        if (count($shops) === 1) {
            return (string) reset($shops);
        }

        return $audience->branchIds === null || count($shops) === count($facts->shops)
            ? 'All '.count($shops).' shops'
            : implode(', ', $shops);
    }
}
