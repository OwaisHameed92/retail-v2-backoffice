<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineStore;
use Carbon\CarbonImmutable;

/**
 * Card orders whose hold ran out without a payment (OS-9, `online-store:expire-payments`, every minute): each is
 * released (ReleaseUnpaidOrder: "payment not completed", never on the board). The slot counts already stop holding
 * at `payment_expires_at`, so this only tidies the status; a payment DNA confirms later still counts. Runs across
 * every business (system job), each order released inside its own store's scope. Returns how many were released.
 */
final class ExpireCardPayments
{
    public function __construct(private readonly ReleaseUnpaidOrder $release) {}

    public function handle(?CarbonImmutable $now = null): int
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $count = 0;

        OnlineOrder::withoutCompanyScope()->where('status', OnlineOrderStatus::AwaitingPayment->value)
            ->where('payment_expires_at', '<=', $now->format('Y-m-d H:i:s'))
            ->orderBy('payment_expires_at')->limit(500)->get(['id', 'online_store_id'])
            ->groupBy('online_store_id')
            ->each(function ($orders, string $storeId) use (&$count): void {
                $store = OnlineStore::withoutCompanyScope()->find($storeId);

                foreach ($store === null ? [] : $orders as $order) {
                    $count += $this->release->handle($store, $order->id, 'Not paid within the time allowed') ? 1 : 0;
                }
            });

        return $count;
    }
}
