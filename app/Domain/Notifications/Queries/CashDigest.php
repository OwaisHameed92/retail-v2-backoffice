<?php

namespace App\Domain\Notifications\Queries;

use App\Domain\Cash\Data\CashFilters;
use App\Domain\Cash\Queries\VarianceAlerts;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\Shared\Support\Money;

/**
 * Cash part of the daily digest (module 7.8): yesterday's variance alerts per shop (module 5.4, VarianceAlerts: the
 * shop's own `cash.variance_alert_over` amount, else £5), shifts, safe counts and bankings. Run as the business
 * (DigestFindings sets CurrentCompany).
 */
final class CashDigest
{
    /**
     * @param  array<string, string>  $shops  active shop names by id
     * @return array<string, array{total: int, counts: array<string, int>, items: list<string>}>
     */
    public static function for(array $shops, string $day): array
    {
        $out = [];

        foreach ($shops as $branchId => $name) {
            $result = VarianceAlerts::for(new CashFilters(from: $day, to: $day, shop: (string) $branchId), 1, 500);
            $rows = $result['alerts']['data'];

            if ($rows === []) {
                continue;
            }

            $out[(string) $branchId] = [
                'total' => count($rows),
                'counts' => ['count' => count($rows), 'short' => (int) $result['summary']['short']],
                'items' => array_map(fn (array $r) => $name.(is_string($r['till'] ?? null) ? ', '.$r['till'] : '').': '.$r['what'].' '
                    .MailFormat::money(ltrim((string) $r['variance'], '-')).(Money::isNegative((string) $r['variance']) ? ' short' : ' over'),
                    array_slice($rows, 0, DigestFindings::ITEMS)),
            ];
        }

        return $out;
    }
}
