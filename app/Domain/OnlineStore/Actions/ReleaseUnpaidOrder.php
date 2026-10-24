<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Support\StoreScope;
use App\Domain\Shared\Actions\RecordAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A card order that was not paid gives its slot back (OS-9): DNA said the payment failed, the customer came back
 * from DNA's page without paying, DNA could not be reached, or the hold ran out (ExpireCardPayments). The order
 * becomes "payment not completed" (never on the board) with the reason. Only an order still awaiting payment is
 * changed (row locked), so a payment that went through is never undone. Returns whether it changed. Audited.
 */
final class ReleaseUnpaidOrder
{
    public const REASON_MAX = 255;

    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(OnlineStore $store, string $orderId, string $reason): bool
    {
        $order = StoreScope::run($store, fn (): ?OnlineOrder => DB::transaction(function () use ($store, $orderId, $reason): ?OnlineOrder {
            $order = OnlineOrder::query()->where('company_id', $store->company_id)->where('online_store_id', $store->id)
                ->whereKey($orderId)->lockForUpdate()->first();

            if ($order === null || $order->status !== OnlineOrderStatus::AwaitingPayment) {
                return null;
            }

            $order->forceFill([
                'status' => OnlineOrderStatus::PaymentFailed,
                'payment_error' => mb_substr(trim($reason) === '' ? 'Not paid' : trim($reason), 0, self::REASON_MAX),
                'payment_expires_at' => CarbonImmutable::now('UTC'),
            ])->save();

            return $order;
        }));

        if ($order === null) {
            return false;
        }

        $this->audit->handle('online_order.payment_failed', $order, ['status' => OnlineOrderStatus::AwaitingPayment->value],
            ['status' => OnlineOrderStatus::PaymentFailed->value, 'payment_error' => $order->payment_error], ['number' => $order->reference], companyId: $store->company_id);

        return true;
    }
}
