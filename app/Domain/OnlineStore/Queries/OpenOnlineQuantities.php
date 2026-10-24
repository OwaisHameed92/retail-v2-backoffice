<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Enums\OrderLineOutcome;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\Shared\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * How much of each product the shop's open online orders already hold (audit OSA-12): the stock is still on the
 * shelf until the till rings the order up, so it is taken off what a new order may have. Counts orders still to be
 * worked and unpaid card orders while their hold lasts; lines the shop marked unavailable do not count.
 */
final class OpenOnlineQuantities
{
    /**
     * @param  list<string>  $productIds
     * @return array<string, string> product id => quantity ("3.0000")
     */
    public static function for(OnlineStoreShop $shop, array $productIds, ?CarbonImmutable $now = null): array
    {
        if ($productIds === []) {
            return [];
        }

        $now = ($now ?? CarbonImmutable::now())->utc()->format('Y-m-d H:i:s');
        $open = [OnlineOrderStatus::New->value, OnlineOrderStatus::Picking->value, OnlineOrderStatus::Ready->value, OnlineOrderStatus::OutForDelivery->value];

        return DB::table('online_order_lines as l')
            ->join('online_orders as o', 'o.id', '=', 'l.online_order_id')
            ->where('o.company_id', $shop->company_id)->where('l.company_id', $shop->company_id)
            ->where('o.branch_id', $shop->branch_id)
            ->whereIn('l.product_id', $productIds)
            ->where(fn ($q) => $q->whereNull('l.outcome')->orWhere('l.outcome', '!=', OrderLineOutcome::Unavailable->value))
            ->where(fn ($q) => $q->whereIn('o.status', $open)
                ->orWhere(fn ($w) => $w->where('o.status', OnlineOrderStatus::AwaitingPayment->value)->where('o.payment_expires_at', '>', $now)))
            ->groupBy('l.product_id')
            ->selectRaw('l.product_id as product_id, SUM(l.quantity) as held')
            ->get()
            ->mapWithKeys(fn (object $row) => [(string) $row->product_id => Money::normalise((string) $row->held, 4)])
            ->all();
    }
}
