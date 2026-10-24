<?php

namespace App\Http\Support\Store;

use App\Domain\OnlineStore\Basket\BasketRules;
use App\Domain\OnlineStore\Enums\FulfilmentMethod;
use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineOrderLine;
use App\Domain\OnlineStore\Queries\CollectionDays;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use Carbon\CarbonImmutable;

/**
 * The public order page's order (OS-2, OS-9): reference, where it is (status steps with their times, shop time; the
 * delivery steps for a delivery), the slot, the delivery address, how it is paid, the lines as ordered (and what the
 * shop changed, OSA-18), and the totals. The customer's mobile and email are masked (audit OSA-22: the link may be
 * forwarded).
 */
final class StoreOrderPresenter
{
    private const COLLECTION_STEPS = [
        ['status' => OnlineOrderStatus::New, 'label' => 'Order received', 'hint' => 'The shop has your order.'],
        ['status' => OnlineOrderStatus::Picking, 'label' => 'Being picked', 'hint' => 'Your items are being picked from the shelves.'],
        ['status' => OnlineOrderStatus::Ready, 'label' => 'Ready to collect', 'hint' => 'Come to the counter and give your order number.'],
        ['status' => OnlineOrderStatus::Collected, 'label' => 'Collected', 'hint' => 'Paid and collected. Enjoy.'],
    ];

    private const DELIVERY_STEPS = [
        ['status' => OnlineOrderStatus::New, 'label' => 'Order received', 'hint' => 'The shop has your order.'],
        ['status' => OnlineOrderStatus::Picking, 'label' => 'Being picked', 'hint' => 'Your items are being picked from the shelves.'],
        ['status' => OnlineOrderStatus::Ready, 'label' => 'Packed', 'hint' => 'Your order is packed and waiting for the driver.'],
        ['status' => OnlineOrderStatus::OutForDelivery, 'label' => 'On its way', 'hint' => 'Your order is out for delivery.'],
        ['status' => OnlineOrderStatus::Delivered, 'label' => 'Delivered', 'hint' => 'Delivered. Enjoy.'],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function order(OnlineOrder $order): array
    {
        $delivery = $order->isDelivery();
        $lines = $order->lines->map(fn (OnlineOrderLine $line) => [
            'name' => $line->name,
            'quantity' => BasketRules::display($line->quantity),
            'isWeighed' => $line->is_weighed,
            'unitPrice' => $line->unit_price,
            'lineTotal' => $line->line_total,
            'outcome' => $line->outcome?->value,
            'orderedQuantity' => $line->ordered_quantity === null ? null : BasketRules::display($line->ordered_quantity),
        ])->values()->all();

        return [
            'reference' => $order->reference,
            'status' => $order->status->value,
            'statusLabel' => $order->status->label($order->fulfilment),
            'fulfilment' => $order->fulfilment->value,
            'cancelReason' => $order->status === OnlineOrderStatus::Cancelled ? $order->cancel_reason : null,
            'cancelledAt' => self::time($order->cancelled_at),
            'steps' => self::steps($order),
            'collect' => CollectionDays::describe($order->collect_from, $order->collect_to),
            'placedAt' => self::time($order->created_at),
            'customerName' => $order->customer_name,
            'customerEmail' => self::maskEmail($order->customer_email),
            'customerMobile' => self::maskMobile($order->customer_mobile),
            'deliveryAddress' => $delivery ? $order->deliveryAddress() : null,
            'deliveryNotes' => $delivery ? $order->delivery_notes : null,
            'customerNote' => $order->customer_note,
            'substitutionsOk' => $order->substitutions_ok,
            'payment' => [
                'method' => $order->payment_method->value,
                'label' => $order->payment_method->label(),
                'paid' => $order->isPaid(),
                'pending' => $order->status === OnlineOrderStatus::AwaitingPayment,
                'failed' => $order->status === OnlineOrderStatus::PaymentFailed,
                'paidAmount' => $order->paid_amount,
            ],
            'lines' => $lines,
            'itemsTotal' => $order->items_total,
            'deliveryFee' => $order->delivery_fee,
            'grandTotal' => $order->grandTotal(),
            'estimated' => $order->lines->contains(fn (OnlineOrderLine $line) => $line->is_weighed && $line->outcome === null),
            'changed' => $order->lines->contains(fn (OnlineOrderLine $line) => $line->outcome !== null),
        ];
    }

    /** "a•••@example.com". */
    public static function maskEmail(string $email): string
    {
        [$name, $domain] = explode('@', $email, 2) + [1 => ''];

        return $domain === '' ? '•••' : mb_substr($name, 0, 1).'•••@'.$domain;
    }

    /** "07••• •••123". */
    public static function maskMobile(string $mobile): string
    {
        $digits = preg_replace('/\D+/', '', $mobile) ?? '';

        return strlen($digits) < 6 ? '•••' : substr($digits, 0, 2).'••• •••'.substr($digits, -3);
    }

    /**
     * @return list<array{label: string, hint: string, done: bool, current: bool, time: string|null}>
     */
    private static function steps(OnlineOrder $order): array
    {
        $plan = $order->fulfilment === FulfilmentMethod::Delivery ? self::DELIVERY_STEPS : self::COLLECTION_STEPS;
        $reached = array_search($order->status, array_column($plan, 'status'), true);
        $steps = [];

        foreach ($plan as $i => $step) {
            $column = $step['status']->timeColumn();
            $at = $column === null ? ($order->paid_at ?? $order->created_at) : $order->getAttribute($column);

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
