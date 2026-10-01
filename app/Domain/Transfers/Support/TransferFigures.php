<?php

namespace App\Domain\Transfers\Support;

use App\Domain\Shared\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Transfer discrepancies (module 5.3), in exact decimals (never floats), shown as the till sends them
 * (contract §10.2, ANSWERS-2026-10-01 §6) — the two till figures run opposite ways and are never recalculated:
 *
 * - `StockTransferReceiptLine.qtyVariance` = received − sent: negative = short, positive = over-delivered;
 * - `StockTransferReceipt.varianceCost` = sent cost − received cost: positive = value lost in transit.
 *
 * Per line the portal adds only what the till does not send: sent and received value at the line's unit cost and the
 * line's difference at cost (`qtyVariance` × unit cost, same sign as the quantity). Money totals of a transfer, a
 * route or a period are the receipts' `varianceCost`, summed.
 */
final class TransferFigures
{
    /**
     * One line's figures; `variance` is the till's `qtyVariance` as sent.
     *
     * @return array{sent: string, received: string, variance: string, sentValue: string, receivedValue: string, varianceValue: string}
     */
    public static function line(mixed $sent, mixed $received, mixed $unitCost, mixed $qtyVariance): array
    {
        $sent = Money::normalise($sent ?? 0, Money::QUANTITY_SCALE);
        $received = Money::normalise($received ?? 0, Money::QUANTITY_SCALE);
        $variance = Money::normalise($qtyVariance ?? 0, Money::QUANTITY_SCALE);

        return [
            'sent' => $sent,
            'received' => $received,
            'variance' => $variance,
            'sentValue' => Money::mul($sent, $unitCost ?? 0),
            'receivedValue' => Money::mul($received, $unitCost ?? 0),
            'varianceValue' => Money::mul($variance, $unitCost ?? 0),
        ];
    }

    /**
     * Totals per transfer from its receipt lines (only receipts that are not deleted).
     *
     * @param  list<string>|null  $transferIds  null = every transfer of the current company the caller already narrowed with $restrict
     * @param  (callable(Builder): mixed)|null  $restrict  narrows the receipt lines query (e.g. by a transfer sub-query)
     * @return array<string, array{sentValue: string, receivedValue: string, varianceCost: string, short: string, over: string, lines: int, discrepancies: int}>
     */
    public static function perTransfer(?array $transferIds, string $companyId, ?callable $restrict = null): array
    {
        $query = DB::table('stock_transfer_receipt_lines as rl')
            ->join('stock_transfer_receipts as rr', fn ($j) => $j->on('rr.id', '=', 'rl.receipt_id')->on('rr.company_id', '=', 'rl.company_id'))
            ->where('rl.company_id', $companyId)->whereNull('rl.deleted_at')->whereNull('rr.deleted_at')
            ->select(['rl.transfer_id', 'rl.receipt_id', 'rl.qty_dispatched', 'rl.qty_received', 'rl.qty_variance', 'rl.unit_cost', 'rr.variance_cost']);

        if ($transferIds !== null) {
            $query->whereIn('rl.transfer_id', $transferIds);
        }

        if ($restrict !== null) {
            $restrict($query);
        }

        $totals = [];
        $receipts = [];

        foreach ($query->cursor() as $row) {
            $id = (string) $row->transfer_id;
            $line = self::line($row->qty_dispatched, $row->qty_received, $row->unit_cost, $row->qty_variance);
            $t = $totals[$id] ?? ['sentValue' => '0.00', 'receivedValue' => '0.00', 'varianceCost' => '0.00', 'short' => '0.0000', 'over' => '0.0000', 'lines' => 0, 'discrepancies' => 0];
            $t['sentValue'] = Money::add($t['sentValue'], $line['sentValue']);
            $t['receivedValue'] = Money::add($t['receivedValue'], $line['receivedValue']);
            $t['lines']++;

            // The till's receipt figure, once per receipt.
            if (! isset($receipts[(string) $row->receipt_id])) {
                $receipts[(string) $row->receipt_id] = true;
                $t['varianceCost'] = Money::add($t['varianceCost'], Money::normalise($row->variance_cost ?? 0));
            }

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
