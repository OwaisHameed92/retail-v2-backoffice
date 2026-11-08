<?php

namespace App\Domain\Workshop\Actions;

use App\Domain\Shared\Support\ApiDate;
use App\Domain\Shared\Support\Money;
use App\Domain\Workshop\Enums\WebhookEvent;
use App\Domain\Workshop\Models\WorkshopConnection;
use App\Domain\Workshop\Models\WorkshopOrder;
use App\Domain\Workshop\Support\TillOrderState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Tells BikeBook what the till did with connector orders (WORKSHOP-BIKEBOOK.md §3.7, §3.8): after a push carrying
 * CustomerOrder / CustomerOrderPayment rows, each of our WorkshopOrders those rows belong to is compared with what
 * BikeBook was last told; a change to Complete (collected) or Cancelled (cancelled / expired), or a new refund total,
 * queues one `order.statuschanged` `{id, orderStatus, paidTotal, refundedTotal, saleId, paidAt}`.
 *
 * Only orders we sent are looked at, so an order the till found itself in Direct mode (an `externalOrderId` we never
 * sent) is never reported by us. Deposits and part payments are not reported until BikeBook agrees how (Q5, Q11); an
 * order BikeBook itself marked Complete is never reported back (§5.4). Also records `locked_at` the first time the
 * till's money is seen.
 */
final class ReportWorkshopOrders
{
    public function __construct(private readonly QueueWorkshopWebhook $queue) {}

    /**
     * @param  list<string>  $customerOrderIds  CustomerOrder ids the push touched (directly or by a payment)
     */
    public function handle(WorkshopConnection $connection, array $customerOrderIds): int
    {
        if ($customerOrderIds === []) {
            return 0;
        }

        $linked = DB::table('customer_orders')->where('company_id', $connection->company_id)->whereIn('id', $customerOrderIds)
            ->where('external_provider', 'bikebook')->whereNotNull('external_order_id')->pluck('external_order_id')->map(fn ($id) => (string) $id)->all();
        $orders = WorkshopOrder::query()->where('connection_id', $connection->id)->whereIn('id', [...$customerOrderIds, ...$linked])->get();
        $sent = 0;

        foreach ($orders as $order) {
            $sent += $this->report($connection, $order) ? 1 : 0;
        }

        return $sent;
    }

    private function report(WorkshopConnection $connection, WorkshopOrder $order): bool
    {
        $state = TillOrderState::of($order);

        if ($state->locked() && $order->locked_at === null) {
            $order->forceFill(['locked_at' => CarbonImmutable::now('UTC')])->save();
        }

        $status = $state->bikeBookStatus();

        if ($order->completed_in_bikebook || ! in_array($status, ['Complete', 'Cancelled'], true)) {
            return false;
        }

        if ($order->reported_status === $status && Money::equals($order->reported_paid_total ?? '-1', $state->paidTotal)
            && Money::equals($order->reported_refunded_total ?? '-1', $state->refundedTotal)) {
            return false;
        }

        $delivery = $this->queue->handle($connection, WebhookEvent::OrderStatusChanged, [
            'id' => $order->id,
            'orderStatus' => $status,
            'paidTotal' => (float) $state->paidTotal,
            'refundedTotal' => (float) $state->refundedTotal,
            'saleId' => $state->saleId,
            'paidAt' => ApiDate::format($state->paidAt),
        ], $order->id);

        if ($delivery === null) {
            return false;
        }

        $order->forceFill([
            'reported_status' => $status, 'reported_paid_total' => $state->paidTotal,
            'reported_refunded_total' => $state->refundedTotal, 'reported_at' => CarbonImmutable::now('UTC'),
        ])->save();

        return true;
    }
}
