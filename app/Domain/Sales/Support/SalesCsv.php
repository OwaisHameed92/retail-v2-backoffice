<?php

namespace App\Domain\Sales\Support;

use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Sales\Queries\SaleList;
use App\Domain\Sales\Queries\SaleSearch;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\CsvText;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Writes the filtered sales as CSV (module 4.6): one row per sale, newest first, money as the till's exact pounds,
 * times in London. Text cells that a spreadsheet would run as a formula are prefixed with an apostrophe.
 */
final class SalesCsv
{
    public const HEADERS = [
        'Receipt', 'Type', 'Status', 'Trading day', 'Time', 'Shop', 'Till', 'Staff', 'Customer', 'Card number', 'Items',
        'Subtotal', 'Discounts', 'Promotions', 'VAT', 'Total', 'Tenders', 'Refunded later', 'Sale id',
    ];

    private const CHUNK = 500;

    /**
     * HEADERS in the profile's tax name: GB exactly HEADERS ("VAT"), PK "GST".
     *
     * @return list<string>
     */
    public static function headers(): array
    {
        return array_map(fn (string $header) => Country::tax($header), self::HEADERS);
    }

    /**
     * @param  resource  $out
     * @param  Builder<Sale>  $query
     * @return int rows written
     */
    public static function write($out, Builder $query): int
    {
        fputcsv($out, self::headers(), escape: '');
        $count = 0;

        SaleSearch::chunk($query, self::CHUNK, function (Collection $sales) use ($out, &$count) {
            $rows = SaleList::rows($sales);

            foreach ($sales->values() as $i => $sale) {
                fputcsv($out, self::line($sale, $rows[$i]), escape: '');
                $count++;
            }
        });

        return $count;
    }

    /**
     * @param  array<string, mixed>  $row  SaleList::rows() item
     * @return list<string|int>
     */
    private static function line(Sale $sale, array $row): array
    {
        $at = is_string($row['at']) ? CarbonImmutable::parse($row['at'])->setTimezone(TradingDay::timezone())->format('Y-m-d H:i:s') : '';
        $customer = is_array($row['customer']) ? $row['customer'] : null;

        return [
            self::text($row['receiptNumber']),
            self::text(ucfirst((string) $row['type'])),
            self::text(ucfirst((string) $row['status'])),
            $row['day'],
            $at,
            self::text($row['shop'] ?? ''),
            self::text($row['till'] ?? ''),
            self::text($row['staff'] ?? ''),
            self::text($customer['name'] ?? ''),
            self::text($customer['cardNo'] ?? ''),
            $row['items'],
            Money::normalise($sale->subtotal ?? '0'),
            Money::normalise($sale->discount_total ?? '0'),
            Money::normalise($sale->promo_total ?? '0'),
            Money::normalise($sale->vat_total ?? '0'),
            $row['total'],
            self::text(implode(' + ', $row['tenders'])),
            $row['refunded'] ? 'Yes' : 'No',
            $sale->id,
        ];
    }

    private static function text(mixed $value): string
    {
        return CsvText::safe($value);
    }
}
