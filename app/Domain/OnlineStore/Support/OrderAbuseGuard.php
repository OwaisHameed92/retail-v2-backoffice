<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\OnlineStore\Enums\FulfilmentMethod;
use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Limits against fake orders (audit OSA-1), checked by PlaceCollectOrder while it holds the store's row lock, on top
 * of the per-visitor rate limits and the checkout challenge (CheckoutChallenge). A customer is their email or their
 * mobile (digits): either one matching counts.
 *
 * - At most `online_store.max_open_orders_per_customer` (3) orders still to be worked (or unpaid and held) per store.
 * - At most `online_store.orders_per_customer_hour` (5) orders placed in an hour per store, paid or not.
 * - One order per customer per slot, so one person cannot fill a slot.
 * - At most `online_store.orders_per_store_hour` (60) orders an hour per store; a burst over that is logged.
 *
 * All of them are off with `online_store.customer_limits` false (default on).
 */
final class OrderAbuseGuard
{
    /**
     * @throws ValidationException
     */
    public static function check(OnlineStore $store, FulfilmentMethod $method, CarbonImmutable $slotFrom, string $email, string $mobileKey, CarbonImmutable $now): void
    {
        if (! (bool) config('online_store.customer_limits', true)) {
            return;
        }

        $hourAgo = $now->subHour()->format('Y-m-d H:i:s');
        $inStore = fn (): Builder => OnlineOrder::query()->where('company_id', $store->company_id)->where('online_store_id', $store->id);
        $customer = fn (): Builder => $inStore()->where(fn (Builder $q) => $q->where('customer_email', $email)
            ->when($mobileKey !== '', fn (Builder $w) => $w->orWhere('customer_mobile_key', $mobileKey)));

        if ($inStore()->where('created_at', '>=', $hourAgo)->count() >= max(1, (int) config('online_store.orders_per_store_hour', 60))) {
            Log::warning('Online store: hourly order limit reached', ['store' => $store->id]);

            throw ValidationException::withMessages(['store' => 'We are taking a lot of orders right now. Try again in a few minutes.']);
        }

        if ($customer()->where('created_at', '>=', $hourAgo)->count() >= max(1, (int) config('online_store.orders_per_customer_hour', 5))) {
            Log::notice('Online store: one customer placing many orders', ['store' => $store->id]);

            throw ValidationException::withMessages(['customerEmail' => 'You have placed several orders in the last hour. Call the shop if you need to change one.']);
        }

        $open = self::holding($customer(), $now);

        if ((clone $open)->count() >= max(1, (int) config('online_store.max_open_orders_per_customer', 3))) {
            throw ValidationException::withMessages(['customerEmail' => 'You already have orders waiting with us. Collect or cancel one before placing another.']);
        }

        if ((clone $open)->where('fulfilment', $method->value)->where('collect_from', $slotFrom->utc()->format('Y-m-d H:i:s'))->exists()) {
            throw ValidationException::withMessages(['collectFrom' => 'You already have an order at that time. Choose another time.']);
        }
    }

    /**
     * Orders still to be worked, and card orders still within their payment hold.
     *
     * @param  Builder<OnlineOrder>  $query
     * @return Builder<OnlineOrder>
     */
    private static function holding(Builder $query, CarbonImmutable $now): Builder
    {
        $open = array_map(fn (OnlineOrderStatus $s) => $s->value, array_filter(OnlineOrderStatus::cases(), fn (OnlineOrderStatus $s) => $s->isOpen()));

        return $query->where(fn (Builder $q) => $q->whereIn('status', $open)
            ->orWhere(fn (Builder $w) => $w->where('status', OnlineOrderStatus::AwaitingPayment->value)->where('payment_expires_at', '>', $now->format('Y-m-d H:i:s'))));
    }
}
