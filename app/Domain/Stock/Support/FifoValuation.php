<?php

namespace App\Domain\Stock\Support;

use App\Domain\Shared\Support\Money;

/**
 * FIFO value of one stock line (module 5.1), from the till's cost layers (`FifoStockLayer`: what is left of each
 * delivery and what it cost). Sales use the oldest layers first, so the stock on hand is the newest deliveries:
 *
 * 1. nothing on hand (zero or negative) has no value;
 * 2. layers with something left, newest first (`receivedAt`, then id), cover the quantity on hand at their own cost;
 *    layers beyond it (older stock the till has not used up yet) are left out;
 * 3. what no layer covers is valued at the product's cost price (`Product.costPrice`), or not valued without one.
 *
 * Basis: `fifo` (all from layers), `mixed` (layers + cost price), `cost` (cost price only), `none` (no value).
 * Exact decimals (bcmath through `Money`), 4 places, rounded to pence only at the end.
 */
final class FifoValuation
{
    /**
     * @param  iterable<array{qty: mixed, cost: mixed, at?: string|null, id?: string|null}>  $layers
     * @return array{value: string, fifoQty: string, costQty: string, unvaluedQty: string, basis: string}
     */
    public static function of(mixed $onHand, iterable $layers, mixed $costPrice): array
    {
        $remaining = Money::normalise($onHand ?? 0, 4);
        $zero = '0.0000';

        if (Money::compare($remaining, '0') <= 0) {
            return ['value' => '0.00', 'fifoQty' => $zero, 'costQty' => $zero, 'unvaluedQty' => $zero, 'basis' => 'none'];
        }

        $sorted = [];

        foreach ($layers as $layer) {
            if (Money::compare($layer['qty'] ?? 0, '0') > 0) {
                $sorted[] = $layer;
            }
        }

        usort($sorted, fn (array $a, array $b) => [(string) ($b['at'] ?? ''), (string) ($b['id'] ?? '')] <=> [(string) ($a['at'] ?? ''), (string) ($a['id'] ?? '')]);

        $value = '0';
        $fifoQty = '0';

        foreach ($sorted as $layer) {
            if (Money::compare($remaining, '0') <= 0) {
                break;
            }

            $take = Money::compare($layer['qty'], $remaining) < 0 ? Money::normalise($layer['qty'], 4) : $remaining;
            $value = Money::add($value, Money::mul($take, $layer['cost'] ?? 0, 4), 4);
            $fifoQty = Money::add($fifoQty, $take, 4);
            $remaining = Money::sub($remaining, $take, 4);
        }

        $cost = Money::normalise($costPrice ?? 0, 4);
        $costQty = $zero;
        $unvalued = $zero;

        if (Money::compare($remaining, '0') > 0) {
            if (Money::compare($cost, '0') > 0) {
                $value = Money::add($value, Money::mul($remaining, $cost, 4), 4);
                $costQty = $remaining;
            } else {
                $unvalued = $remaining;
            }
        }

        $hasFifo = Money::compare($fifoQty, '0') > 0;
        $hasCost = Money::compare($costQty, '0') > 0;

        return [
            'value' => Money::round(Money::normalise($value, 4), 2),
            'fifoQty' => Money::normalise($fifoQty, 4),
            'costQty' => $costQty,
            'unvaluedQty' => $unvalued,
            'basis' => match (true) {
                $hasFifo && ! $hasCost => 'fifo',
                $hasFifo => 'mixed',
                $hasCost => 'cost',
                default => 'none',
            },
        ];
    }
}
