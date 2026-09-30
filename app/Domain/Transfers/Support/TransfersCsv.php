<?php

namespace App\Domain\Transfers\Support;

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\StockTransfer;
use App\Domain\Transfers\Data\TransferFilters;
use App\Domain\Transfers\Queries\DiscrepancyReport;
use App\Domain\Transfers\Queries\TransferList;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * CSV exports of module 5.3: the filtered transfer list and the discrepancy lines. Dates in Europe/London, money and
 * quantities as plain decimals, and any cell starting with = + - @ is quoted so a spreadsheet never runs it.
 */
final class TransfersCsv
{
    public const LIST_HEADERS = ['Reference', 'From', 'To', 'Status', 'Receiving till', 'Return', 'Raised', 'Dispatched', 'Received', 'Lines',
        'Value at cost', 'Discrepancy at cost', 'Lines with discrepancies', 'Note', 'Transfer id'];

    public const DISCREPANCY_HEADERS = ['Received', 'Reference', 'From', 'To', 'Product', 'Sent', 'Received qty', 'Difference', 'Unit cost',
        'Sent value', 'Received value', 'Difference at cost', 'Transfer id'];

    private const CHUNK = 200;

    /**
     * @param  resource  $out
     * @param  Builder<StockTransfer>  $query  TransferList::filtered()
     */
    public static function list($out, Builder $query): void
    {
        fputcsv($out, self::LIST_HEADERS, escape: '');

        $query->orderByDesc('stock_transfers.requested_at')->orderByDesc('stock_transfers.id')
            ->chunk(self::CHUNK, function ($chunk) use ($out) {
                /** @var list<StockTransfer> $items */
                $items = array_values($chunk->all());
                $notes = array_column(array_map(fn (StockTransfer $t) => ['id' => $t->id, 'note' => $t->note], $items), 'note', 'id');

                foreach (TransferList::rows($items, null) as $row) {
                    fputcsv($out, array_map(self::text(...), [
                        $row['reference'], $row['from'], $row['to'], self::STATUS[$row['status']] ?? $row['status'], self::RELAY[$row['relay']] ?? $row['relay'],
                        $row['isReturn'] ? 'Yes' : 'No', self::day($row['requestedAt']), self::day($row['dispatchedAt']), self::day($row['receivedAt']),
                        $row['lines'], $row['value'], $row['varianceValue'] ?? '', $row['discrepancies'], $notes[$row['id']] ?? '', $row['id'],
                    ]), escape: '');
                }
            });
    }

    /** @param resource $out */
    public static function discrepancies($out, TransferFilters $filters): void
    {
        fputcsv($out, self::DISCREPANCY_HEADERS, escape: '');

        foreach (DiscrepancyReport::csvLines($filters) as $line) {
            fputcsv($out, array_map(self::text(...), [
                self::day($line['receivedAt']), $line['reference'], $line['from'], $line['to'], $line['productName'] ?? '',
                $line['sent'], $line['received'], $line['variance'], $line['unitCost'], $line['sentValue'], $line['receivedValue'], $line['varianceValue'],
                $line['transferId'],
            ]), escape: '');
        }
    }

    public static function filename(string $kind, TransferFilters $filters): string
    {
        $company = app(CurrentCompany::class)->get()->name ?? 'business';
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($company)), '-') ?: 'business';
        $period = $filters->from !== null || $filters->to !== null ? '-'.($filters->from ?? 'start').'-to-'.($filters->to ?? 'today') : '';

        return "{$slug}-{$kind}{$period}.csv";
    }

    private const STATUS = [
        'requested' => 'Requested', 'dispatched' => 'Dispatched', 'inTransit' => 'In transit', 'received' => 'Received',
        'partlyReceived' => 'Partly received', 'cancelled' => 'Cancelled',
    ];

    private const RELAY = [
        'notRelayed' => 'Not sent to tills', 'waiting' => 'Not pulled yet', 'sent' => 'Pulled', 'stored' => 'On the till', 'received' => 'Received',
    ];

    private static function day(mixed $utc): string
    {
        return is_string($utc) && $utc !== '' ? CarbonImmutable::parse($utc)->setTimezone('Europe/London')->format('Y-m-d H:i') : '';
    }

    private static function text(mixed $value): string
    {
        $value = is_scalar($value) ? (string) $value : '';

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($value) ? "'".$value : $value;
    }
}
