<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineOrderLine;
use App\Domain\Shared\Support\ApiDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * How an online order looks on the portal board and its detail page (React types in
 * `components/app/online-orders/types.ts`). Times are ISO UTC; the page shows them in shop time. Money as "12.50".
 */
final class OrderRows
{
    /** An order placed this recently and still new carries the "Just in" mark. */
    public const FRESH_MINUTES = 15;

    /** Open orders whose slot starts this soon are "Due soon". */
    public const DUE_SOON_MINUTES = 30;

    /**
     * A board card. Needs `lines` and `branch` loaded.
     *
     * @return array<string, mixed>
     */
    public static function card(OnlineOrder $order, CarbonImmutable $now): array
    {
        $names = $order->lines->pluck('name')->all();
        $open = $order->status->isOpen();

        return [
            'id' => $order->id,
            'reference' => $order->reference,
            'status' => $order->status->value,
            'customer' => self::shortName($order->customer_name),
            'itemCount' => self::itemCount($order->lines),
            'preview' => self::preview($names),
            'hasWeighed' => $order->lines->contains('is_weighed', true),
            'total' => $order->items_total,
            'shop' => $order->branch->name ?? '',
            'collectFrom' => ApiDate::format($order->collect_from),
            'collectTo' => ApiDate::format($order->collect_to),
            'placedAt' => ApiDate::format($order->created_at),
            'doneAt' => ApiDate::format($order->collected_at ?? $order->cancelled_at),
            'cancelReason' => $order->cancel_reason,
            'fresh' => $order->status === OnlineOrderStatus::New && $order->created_at !== null && $order->created_at->greaterThan($now->subMinutes(self::FRESH_MINUTES)),
            'late' => $open && $order->collect_to->lessThan($now),
            'dueSoon' => $open && $order->status !== OnlineOrderStatus::Ready && ! $order->collect_to->lessThan($now)
                && $order->collect_from->lessThanOrEqualTo($now->addMinutes(self::DUE_SOON_MINUTES)),
        ];
    }

    /**
     * @return array{id: string, name: string, quantity: string, isWeighed: bool, unitPrice: string, lineTotal: string}
     */
    public static function line(OnlineOrderLine $line): array
    {
        return [
            'id' => $line->id,
            'name' => $line->name,
            'quantity' => self::quantity($line->quantity, $line->is_weighed),
            'isWeighed' => $line->is_weighed,
            'unitPrice' => $line->unit_price,
            'lineTotal' => $line->line_total,
        ];
    }

    /** "2" for items, "0.75" (kg) for a weighed line: trailing zeros off. */
    public static function quantity(string $quantity, bool $weighed): string
    {
        $trimmed = rtrim(rtrim($quantity, '0'), '.');

        return $weighed ? $trimmed : (string) (int) bcadd($quantity, '0', 0);
    }

    /**
     * Items to pick: each unit of a counted product, one per weighed line.
     *
     * @param  Collection<int, OnlineOrderLine>  $lines
     */
    public static function itemCount(Collection $lines): int
    {
        return (int) $lines->sum(fn (OnlineOrderLine $line) => $line->is_weighed ? 1 : max(1, (int) bcadd($line->quantity, '0', 0)));
    }

    /** "Asha Patel" → "Asha P." (cards); the detail page shows the full name. */
    public static function shortName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = (string) array_shift($parts);
        $last = $parts === [] ? '' : (string) end($parts);

        return $last === '' ? $first : $first.' '.mb_strtoupper(mb_substr($last, 0, 1)).'.';
    }

    /**
     * "Beans, Milk +3 more".
     *
     * @param  list<string>  $names
     */
    private static function preview(array $names): string
    {
        $shown = array_slice($names, 0, 2);
        $more = count($names) - count($shown);

        return implode(', ', $shown).($more > 0 ? " +{$more} more" : '');
    }
}
