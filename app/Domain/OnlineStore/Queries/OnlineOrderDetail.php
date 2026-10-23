<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineOrderLine;
use App\Domain\OnlineStore\Support\OrderRows;
use App\Domain\Shared\Support\ApiDate;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * One online order for its portal page and printable pick list: lines (name, quantity, price, weighed note), the
 * customer's details, the collection slot and shop, and when it reached each stage. The order comes from
 * PortalOrders::find (already checked against the user's business and shop).
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

        return [
            'business' => $company->name,
            'order' => [
                ...OrderRows::card($order, $now),
                'customerName' => $order->customer_name,
                'customerMobile' => $order->customer_mobile,
                'customerEmail' => $order->customer_email,
                'shopAddress' => self::address($order),
                'next' => array_map(fn (OnlineOrderStatus $s) => $s->value, $order->status->next()),
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
        $steps = [['status' => OnlineOrderStatus::New->value, 'at' => ApiDate::format($order->created_at), 'note' => null]];

        foreach ([OnlineOrderStatus::Picking, OnlineOrderStatus::Ready, OnlineOrderStatus::Collected, OnlineOrderStatus::Cancelled] as $status) {
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
