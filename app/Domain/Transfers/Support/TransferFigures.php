<?php

namespace App\Domain\Transfers\Support;

use App\Domain\Shared\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Discrepancy math for received transfers (module 5.3), in exact decimals (never floats).
 *
 * Per receipt line: variance = received − sent (negative = short, positive = over); every value is at the line's unit
 * cost, rounded to the penny per line; variance value = received value − sent value. The till's own `qtyVariance` /
 * `varianceCost` are not used: the contract does not fix their sign, and the portal works it out the same way for
 * every shop.
 */
final class TransferFigures
{
    /**
     * One line's figures.
     *
     * @return array{sent: string, received: string, variance: string, sentValue: string, receivedValue: string, varianceValue: string}
     */
    public static function line(mixed $sent, mixed $received, mixed $unitCost): array
    {
        $sent = Money::normalise($sent ?? 0, Money::QUANTITY_SCALE);
        $received = Money::normalise($received ?? 0, Money::QUANTITY_SCALE);
        $sentValue = Money::mul($sent, $unitCost ?? 0);
        $receivedValue = Money::mul($received, $unitCost ?? 0);

        return [
            'sent' => $sent,
            'received' => $received,
            'variance' => Money::sub($received, $sent, Money::QUANTITY_SCALE),
            'sentValue' => $sentValue,
            'receivedValue' => $receivedValue,
            'varianceValue' => Money::sub($receivedValue, $sentValue),
        ];
    }

    /**
     * Totals per transfer from its receipt lines (only receipts that are not deleted).
     *
     * @param  list<string>|null  $transferIds  null = every transfer of the current company the caller already narrowed with $restrict
     * @param  (callable(Builder): mixed)|null  $restrict  narrows the receipt lines query (e.g. by a transfer sub-query)
     * @return array<string, array{sentValue: string, receivedValue: string, varianceValue: string, short: string, over: string, lines: int, discrepancies: int}>
     */
    public static function perTransfer(?array $transferIds, string $companyId, ?callable $restrict = null): array
    {
        $query = DB::table('stock_transfer_receipt_lines as rl')
            ->join('stock_transfer_receipts as rr', fn ($j) => $j->on('rr.id', '=', 'rl.receipt_id')->on('rr.company_id', '=', 'rl.company_id'))
            ->where('rl.company_id', $companyId)->whereNull('rl.deleted_at')->whereNull('rr.deleted_at')
            ->select(['rl.transfer_id', 'rl.qty_dispatched', 'rl.qty_received', 'rl.unit_cost']);

        if ($transferIds !== null) {
            $query->whereIn('rl.transfer_id', $transferIds);
        }

        if ($restrict !== null) {
            $restrict($query);
        }

        $totals = [];

        foreach ($query->cursor() as $row) {
            $id = (string) $row->transfer_id;
            $line = self::line($row->qty_dispatched, $row->qty_received, $row->unit_cost);
            $t = $totals[$id] ?? ['sentValue' => '0.00', 'receivedValue' => '0.00', 'varianceValue' => '0.00', 'short' => '0.0000', 'over' => '0.0000', 'lines' => 0, 'discrepancies' => 0];
            $t['sentValue'] = Money::add($t['sentValue'], $line['sentValue']);
            $t['receivedValue'] = Money::add($t['receivedValue'], $line['receivedValue']);
            $t['varianceValue'] = Money::add($t['varianceValue'], $line['varianceValue']);
            $t['lines']++;

            if (! Money::isZero($line['variance'])) {
                $t['discrepancies']++;
                $side = Money::isNegative($line['variance']) ? 'short' : 'over';
                $t[$side] = Money::add($t[$side], Money::normalise(ltrim($line['variance'], '-'), Money::QUANTITY_SCALE), Money::QUANTITY_SCALE);
            }

            $totals[$id] = $t;
        }

        return $totals;
    }
}
