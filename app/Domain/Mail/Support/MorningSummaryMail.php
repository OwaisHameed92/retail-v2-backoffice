<?php

namespace App\Domain\Mail\Support;

use App\Domain\Shared\Country\LocalText;

/**
 * The morning summary block of the 07:00 email (module 6.3), as ready-to-print lines for the Markdown template, and
 * the sample shown in the admin email previews. Input: the summary array of OwnerDigestData (SummaryView + narrative).
 */
final class MorningSummaryMail
{
    /**
     * @param  array<string, mixed>  $s
     * @return array{intro: string, narrative: string|null, rows: list<array{name: string, sales: string, week: string, year: string, total: bool}>, up: list<string>, down: list<string>, watch: list<string>, url: string, unsubscribeUrl: string}
     */
    public static function lines(array $s): array
    {
        $total = (array) $s['total'];
        $shops = array_values((array) $s['shops']);
        $row = fn (array $r, bool $isTotal) => [
            'name' => $isTotal ? 'Total' : (string) $r['name'],
            'sales' => MailFormat::money((string) $r['sales']),
            'week' => self::change($r['lastWeekChange'] ?? null, $r['lastWeek'] ?? null),
            'year' => self::change($r['lastYearChange'] ?? null, $r['lastYear'] ?? null),
            'total' => $isTotal,
        ];
        $rows = array_map(fn ($r) => $row((array) $r, false), $shops);

        if (count($shops) > 1) {
            $rows[] = $row($total, true);
        } elseif ($rows === []) {
            $rows[] = $row($total, true);
        }

        $mover = fn ($m) => (string) $m['name'].': '.MailFormat::money((string) $m['sales']).' ('
            .(str_starts_with((string) $m['difference'], '-') ? '-' : '+').MailFormat::money(ltrim((string) $m['difference'], '-')).' on last week)';
        $txn = (int) $total['transactions'];

        return [
            'intro' => (string) $s['scope'].', '.(string) $s['dayLabel'].': '.MailFormat::count($txn, 'sale')
                .($total['average'] !== null ? ', average basket '.MailFormat::money((string) $total['average']) : '').'.',
            'narrative' => is_string($s['narrative'] ?? null) ? $s['narrative'] : null,
            'rows' => $rows,
            'up' => array_map($mover, (array) $s['moversUp']),
            'down' => array_map($mover, (array) $s['moversDown']),
            'watch' => array_map(fn ($w) => (string) $w['text'], (array) $s['watch']),
            'url' => (string) $s['url'],
            'unsubscribeUrl' => (string) $s['unsubscribeUrl'],
        ];
    }

    /** "+12.5%", "-3%", or "—" when that day had no sales. */
    public static function change(mixed $change, mixed $before): string
    {
        if ($before === null) {
            return '—';
        }

        if ($change === null) {
            return 'n/a';
        }

        return (str_starts_with((string) $change, '-') ? '' : '+').$change.'%';
    }

    /**
     * @return array<string, mixed>
     */
    public static function sample(string $portal, string $settings): array
    {
        $shop = fn (string $name, string $sales, int $txn, string $week, string $weekChange, ?string $year, ?string $yearChange) => [
            'id' => '', 'name' => $name, 'sales' => $sales, 'transactions' => $txn, 'lastWeek' => $week, 'lastWeekChange' => $weekChange,
            'lastYear' => $year, 'lastYearChange' => $yearChange,
        ];

        return [
            'day' => '2026-10-07',
            'dayLabel' => 'Wednesday 7 October 2026',
            'scope' => 'All 2 shops',
            'total' => [...$shop('', '3120.40', 268, '2890.10', '8.0', null, null), 'average' => '11.64'],
            'shops' => [
                $shop(LocalText::places('Leeds'), '2010.25', 171, '1765.60', '13.9', '1702.00', '18.1'),
                $shop(LocalText::places('Bradford'), '1110.15', 97, '1124.50', '-1.3', null, null),
            ],
            'moversUp' => [['name' => 'Coca-Cola 500ml', 'sales' => '84.00', 'before' => '60.00', 'difference' => '24.00']],
            'moversDown' => [['name' => 'Warburtons Toastie 800g', 'sales' => '12.50', 'before' => '30.00', 'difference' => '-17.50']],
            'watch' => [
                ['kind' => 'refunds', 'text' => LocalText::places('Bradford: ').MailFormat::money('84').' refunded (6 refunds) yesterday, against a usual '.MailFormat::money('21.5').' a day'],
                ['kind' => 'stock', 'text' => LocalText::places('Leeds: Coca-Cola 500ml is low, 4 on hand with 63 sold in the last 7 days')],
            ],
            'narrative' => 'Yesterday your 2 shops took '.MailFormat::money('3120.40').LocalText::places(', up 8.0% on the same day last week. Leeds led the way at +13.9%, while Bradford was close to last week at -1.3%. Bradford refunded ').MailFormat::money('84').', against a usual '.MailFormat::money('21.5').LocalText::places(' a day, so it is worth a look. Coca-Cola 500ml is running low at Leeds.'),
            'url' => $portal.'/app',
            'unsubscribeUrl' => $settings,
        ];
    }
}
