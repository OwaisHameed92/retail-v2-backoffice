<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Actions\UndoOnlineOrderStatus;
use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineOrderLine;
use App\Domain\OnlineStore\Support\OrderRows;
use App\Domain\OnlineStore\Support\StoreLinks;
use App\Domain\Shared\Support\ApiDate;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * One online order for its portal page and printable pick list: lines (name, quantity, price, weighed note, what the
 * shop changed: OSA-18), the customer's details and note, the slot and shop, the delivery address (OS-9), how it is
 * paid (and the refund to make in the DNA portal), and when it reached each stage. Also whether its last move can be
 * undone (OSA-21) and until when its order link works (OSA-22). The order comes from PortalOrders::find (already
 * checked against the user's business and shop).
 */
final class OnlineOrderDetail
{
    /**
     * @return array<string, mixed>
     */
    public static function for(OnlineOrder $order, Company $company, ?CarbonImmutable $now = null): array
    {
        $order->loadMissing(['lines', 'branch:id,name,address,town,postcode']);
        $now = ($now ?? CarbonImmutable::now())->utc();
        $undo = UndoOnlineOrderStatus::canUndo($order, $now);
        $refund = $order->isPaid() ? Money::sub((string) $order->paid_amount, $order->status === OnlineOrderStatus::Cancelled ? '0' : $order->grandTotal(), 2) : null;

        return [
            'business' => $company->name,
            'order' => [
                ...OrderRows::card($order, $now),
                'customerName' => $order->customer_name,
                'customerMobile' => $order->customer_mobile,
                'customerEmail' => $order->customer_email,
                'customerNote' => $order->customer_note,
                'substitutionsOk' => $order->substitutions_ok,
                'shopAddress' => self::address($order),
                'deliveryAddress' => $order->deliveryAddress(),
                'deliveryNotes' => $order->delivery_notes,
                'itemsTotal' => $order->items_total,
                'deliveryFee' => $order->delivery_fee,
                'paidAmount' => $order->paid_amount,
                'paymentReference' => $order->payment_reference,
                'refundDue' => $refund !== null && Money::compare($refund, '0') > 0 ? $refund : null,
                'next' => array_map(fn (OnlineOrderStatus $s) => $s->value, $order->status->next($order->fulfilment)),
                'canChangeLines' => in_array($order->status, [OnlineOrderStatus::New, OnlineOrderStatus::Picking, OnlineOrderStatus::Ready], true),
                'undo' => $undo ? [
                    'to' => (string) $order->previous_status,
                    'until' => ApiDate::format($order->status_changed_at?->addMinutes(max(1, (int) config('online_store.undo_minutes', 10)))),
                ] : null,
                'anonymised' => $order->anonymised_at !== null,
                'linkExpiresAt' => ApiDate::format(StoreLinks::expiresAt($order)),
                'lines' => $order->lines->map(fn (OnlineOrderLine $line) => OrderRows::line($line))->values()->all(),
                'history' => self::history($order),
            ],
        ];
    }

    /**
     * The stages it reached, oldest first.
     *
     * @return list<array{status: string, at: string|null, note: string|null}>
     */
    private static function history(OnlineOrder $order): array
    {
        $steps = [['status' => OnlineOrderStatus::New->value, 'at' => ApiDate::format($order->paid_at ?? $order->created_at), 'note' => $order->isPaid() ? 'Paid by card online' : null]];

        foreach ([OnlineOrderStatus::Picking, OnlineOrderStatus::Ready, OnlineOrderStatus::OutForDelivery, OnlineOrderStatus::Collected,
            OnlineOrderStatus::Delivered, OnlineOrderStatus::NotCollected, OnlineOrderStatus::Cancelled] as $status) {
            $at = $order->getAttribute((string) $status->timeColumn());

            if ($at !== null) {
                $steps[] = ['status' => $status->value, 'at' => ApiDate::format($at), 'note' => $status === OnlineOrderStatus::Cancelled ? $order->cancel_reason : null];
            }
        }

        usort($steps, fn (array $a, array $b) => strcmp((string) $a['at'], (string) $b['at']));

        return $steps;
    }

    private static function address(OnlineOrder $order): ?string
    {
        $branch = $order->branch;
        $parts = array_filter([$branch->getAttribute('address'), $branch->getAttribute('town'), $branch->getAttribute('postcode')], fn ($p) => is_string($p) && trim($p) !== '');

        return $parts === [] ? null : implode(', ', array_map('trim', $parts));
    }
}
