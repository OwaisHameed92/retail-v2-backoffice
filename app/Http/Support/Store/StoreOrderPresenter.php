<?php

namespace App\Http\Support\Store;

use App\Domain\OnlineStore\Basket\BasketRules;
use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineOrderLine;
use App\Domain\OnlineStore\Queries\CollectionDays;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use Carbon\CarbonImmutable;

/**
 * The public order page's order (OS-2): reference, where it is (status steps with their times, shop time), the
 * collection time, the lines as ordered (names and prices as they were) and the total to pay at the shop.
 */
final class StoreOrderPresenter
{
    private const STEPS = [
        ['status' => OnlineOrderStatus::New, 'label' => 'Order received', 'hint' => 'The shop has your order.'],
        ['status' => OnlineOrderStatus::Picking, 'label' => 'Being picked', 'hint' => 'Your items are being picked from the shelves.'],
        ['status' => OnlineOrderStatus::Ready, 'label' => 'Ready to collect', 'hint' => 'Come to the counter and give your order number.'],
        ['status' => OnlineOrderStatus::Collected, 'label' => 'Collected', 'hint' => 'Paid and collected. Enjoy.'],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function order(OnlineOrder $order): array
    {
        $lines = $order->lines->map(fn (OnlineOrderLine $line) => [
            'name' => $line->name,
            'quantity' => BasketRules::display($line->quantity),
            'isWeighed' => $line->is_weighed,
            'unitPrice' => $line->unit_price,
            'lineTotal' => $line->line_total,
        ])->values()->all();

        return [
            'reference' => $order->reference,
            'status' => $order->status->value,
            'statusLabel' => $order->status->label(),
            'cancelReason' => $order->status === OnlineOrderStatus::Cancelled ? $order->cancel_reason : null,
            'cancelledAt' => self::time($order->cancelled_at),
            'steps' => self::steps($order),
            'collect' => CollectionDays::describe($order->collect_from, $order->collect_to),
            'placedAt' => self::time($order->created_at),
            'customerName' => $order->customer_name,
            'customerEmail' => $order->customer_email,
            'customerMobile' => $order->customer_mobile,
            'lines' => $lines,
            'itemsTotal' => $order->items_total,
            'estimated' => $order->lines->contains(fn (OnlineOrderLine $line) => $line->is_weighed),
        ];
    }

    /**
     * @return list<array{label: string, hint: string, done: bool, current: bool, time: string|null}>
     */
    private static function steps(OnlineOrder $order): array
    {
        $reached = array_search($order->status, array_column(self::STEPS, 'status'), true);
        $steps = [];

        foreach (self::STEPS as $i => $step) {
            $column = $step['status']->timeColumn();
            $at = $column === null ? $order->created_at : $order->getAttribute($column);

            $steps[] = [
                'label' => $step['label'],
                'hint' => $step['hint'],
                'done' => $reached !== false && $i <= $reached,
                'current' => $reached === $i,
                'time' => $at instanceof CarbonImmutable ? self::time($at) : null,
            ];
        }

        return $steps;
    }

    /** "3 Nov, 10:42" in shop time. */
    private static function time(?CarbonImmutable $at): ?string
    {
        return $at?->setTimezone(OnlineStoreSwitch::timezone())->format('j M, H:i');
    }
}
