<?php

namespace App\Domain\Workshop\Actions;

use App\Domain\Workshop\Data\SalesOrderLineInput;
use App\Domain\Workshop\Enums\WorkshopOrderStatus;
use App\Domain\Workshop\Models\WorkshopConnection;
use App\Domain\Workshop\Models\WorkshopOrder;
use App\Domain\Workshop\Models\WorkshopOrderLine;
use App\Domain\Workshop\Support\ConnectorException;
use App\Domain\Workshop\Support\TillOrderState;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * BikeBook changes one line, cancels, or sets the status of a sales order (WORKSHOP-BIKEBOOK.md §3.6, §5.3, §5.4).
 * Called inside the connection's company. Every change is a new WorkshopOrder version (SaveSalesOrder::mutate) and is
 * refused with 409 `order_locked` once the till has taken money on the order or closed it. A change that changes
 * nothing (the same line again, a cancel of a cancelled order) answers the order as it is: idempotent by natural key.
 *
 * - `addLine` / `updateLine`: idempotent by line id (ours, or BikeBook's own `id` on the line).
 * - `cancel` (`DELETE /api/SalesOrder`, status `Cancelled`): `status: cancelled`; the till drops the order.
 * - `Saved`: re-opens a cancelled order. `Complete` (paid in full in BikeBook): sent again still open with
 *   `paidElsewhere` = the total; never reported back to BikeBook (it already knows, §5.4).
 */
final class ChangeSalesOrder
{
    public function __construct(private readonly SaveSalesOrder $save) {}

    public function addLine(WorkshopConnection $connection, string $orderId, SalesOrderLineInput $line): WorkshopOrder
    {
        return $this->locked($connection, $orderId, function (WorkshopOrder $order) use ($line): WorkshopOrder {
            $lines = $this->lines($order);

            // BikeBook's own line id again: the same line (a retry, or a change of it).
            if ($line->externalLineId !== '' && collect($lines)->contains(fn (SalesOrderLineInput $l) => $l->externalLineId === $line->externalLineId)) {
                return $this->updateIn($order, $line->externalLineId, $line);
            }

            TillOrderState::ensureUnlocked($order);
            $max = (int) config('workshop.max_order_lines', 200);

            if (count($lines) >= $max) {
                throw ConnectorException::invalid("An order can have at most {$max} lines.");
            }

            return $this->save->mutate($order, [], [...$lines, $line]);
        });
    }

    public function updateLine(WorkshopConnection $connection, string $lineId, SalesOrderLineInput $line): WorkshopOrder
    {
        $order = $this->orderOfLine($connection, $lineId);

        return $this->locked($connection, $order->id, fn (WorkshopOrder $locked) => $this->updateIn($locked, $lineId, $line));
    }

    public function deleteLine(WorkshopConnection $connection, string $lineId): WorkshopOrder
    {
        $order = $this->orderOfLine($connection, $lineId);

        return $this->locked($connection, $order->id, function (WorkshopOrder $locked) use ($lineId): WorkshopOrder {
            $stored = $locked->lines()->get();
            $keep = $stored->reject(fn (WorkshopOrderLine $l) => $l->id === $lineId || $l->external_line_id === $lineId);

            if ($keep->count() === $stored->count()) {
                return $locked;
            }

            if ($keep->isEmpty()) {
                throw ConnectorException::invalid('An order needs at least one line. Cancel the order instead.');
            }

            TillOrderState::ensureUnlocked($locked);

            return $this->save->mutate($locked, [], $keep->map(fn (WorkshopOrderLine $l) => SalesOrderLineInput::fromStored($l))->values()->all());
        });
    }

    public function cancel(WorkshopConnection $connection, string $orderId): WorkshopOrder
    {
        return $this->locked($connection, $orderId, function (WorkshopOrder $order): WorkshopOrder {
            if ($order->status === WorkshopOrderStatus::Cancelled) {
                return $order;
            }

            TillOrderState::ensureUnlocked($order);

            return $this->save->mutate($order, ['status' => WorkshopOrderStatus::Cancelled->value, 'cancelled_at' => CarbonImmutable::now('UTC')]);
        });
    }

    public function status(WorkshopConnection $connection, string $orderId, string $status): WorkshopOrder
    {
        return match (strtolower($status)) {
            'cancelled', 'canceled' => $this->cancel($connection, $orderId),
            'saved' => $this->locked($connection, $orderId, function (WorkshopOrder $order): WorkshopOrder {
                if ($order->status === WorkshopOrderStatus::Open && ! $order->completed_in_bikebook) {
                    return $order;
                }

                TillOrderState::ensureUnlocked($order);

                return $this->save->mutate($order, ['status' => WorkshopOrderStatus::Open->value, 'cancelled_at' => null, 'completed_in_bikebook' => false, 'paid_elsewhere' => '0.00']);
            }),
            'complete', 'completed' => $this->locked($connection, $orderId, function (WorkshopOrder $order): WorkshopOrder {
                if ($order->completed_in_bikebook || TillOrderState::of($order)->bikeBookStatus() === 'Complete') {
                    return $order;
                }

                TillOrderState::ensureUnlocked($order);

                return $this->save->mutate($order, ['status' => WorkshopOrderStatus::Open->value, 'cancelled_at' => null, 'completed_in_bikebook' => true, 'paid_elsewhere' => $order->goods_total]);
            }),
            default => throw ConnectorException::invalid('status must be Saved, Complete or Cancelled.'),
        };
    }

    private function updateIn(WorkshopOrder $order, string $lineId, SalesOrderLineInput $line): WorkshopOrder
    {
        $stored = $order->lines()->get();
        $target = $stored->first(fn (WorkshopOrderLine $l) => $l->id === $lineId || ($l->external_line_id !== '' && $l->external_line_id === $lineId));

        if (! $target instanceof WorkshopOrderLine) {
            throw ConnectorException::notFound('No sales order line with this id.');
        }

        $line = $line->externalLineId === '' && $target->external_line_id !== '' ? $line->withExternalId($target->external_line_id) : $line;
        $lines = $stored->map(fn (WorkshopOrderLine $l) => $l->id === $target->id ? $line : SalesOrderLineInput::fromStored($l))->values()->all();

        if (SalesOrderLineInput::fromStored($target) == $line) {
            return $order;
        }

        TillOrderState::ensureUnlocked($order);

        return $this->save->mutate($order, [], $lines);
    }

    /**
     * @return list<SalesOrderLineInput>
     */
    private function lines(WorkshopOrder $order): array
    {
        return $order->lines()->get()->map(fn (WorkshopOrderLine $l) => SalesOrderLineInput::fromStored($l))->values()->all();
    }

    private function orderOfLine(WorkshopConnection $connection, string $lineId): WorkshopOrder
    {
        $line = WorkshopOrderLine::query()->whereKey($lineId)->first()
            ?? WorkshopOrderLine::query()->where('external_line_id', $lineId)->where('external_line_id', '<>', '')
                ->whereIn('workshop_order_id', WorkshopOrder::query()->where('connection_id', $connection->id)->select('id'))->first();
        $order = $line === null ? null : WorkshopOrder::query()->where('connection_id', $connection->id)->whereKey($line->workshop_order_id)->first();

        return $order ?? throw ConnectorException::notFound('No sales order line with this id.');
    }

    /**
     * @param  Closure(WorkshopOrder): WorkshopOrder  $change
     */
    private function locked(WorkshopConnection $connection, string $orderId, Closure $change): WorkshopOrder
    {
        return DB::transaction(function () use ($connection, $orderId, $change): WorkshopOrder {
            $order = WorkshopOrder::query()->where('connection_id', $connection->id)->whereKey($orderId)->lockForUpdate()->first();

            return $change($order ?? throw ConnectorException::notFound('No sales order with this id for this shop.'));
        });
    }
}
