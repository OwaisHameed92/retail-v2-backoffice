<?php

namespace App\Domain\Workshop\Actions;

use App\Domain\TillData\Sync\HubVersions;
use App\Domain\Workshop\Data\SalesOrderInput;
use App\Domain\Workshop\Data\SalesOrderLineInput;
use App\Domain\Workshop\Enums\WorkshopOrderStatus;
use App\Domain\Workshop\Models\WorkshopConnection;
use App\Domain\Workshop\Models\WorkshopOrder;
use App\Domain\Workshop\Models\WorkshopOrderLine;
use App\Domain\Workshop\Support\ConnectorException;
use App\Domain\Workshop\Support\TillOrderState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * BikeBook's "Send to POS" (WORKSHOP-BIKEBOOK.md §3.6, §5): makes or replaces a WorkshopOrder for the connected shop.
 * Called inside the connection's company.
 *
 * - Idempotent by `orderNumber` (per connection): the same body again answers the same order, no second row and no
 *   new version; a POST with an order number we already have is a replace (so a retry after a portal outage still
 *   gives one order, joint test 12).
 * - Replace: refused with 409 `order_locked` once the till has taken money on it or closed it (TillOrderState).
 *   Otherwise a new version: header and lines replaced (our line ids kept where BikeBook's line id, else the
 *   position, matches), `hub_version` cleared and stamped after commit so the shop's next pull carries it.
 * - A cancelled order sent again with new content is open again.
 *
 * `mutate()` is the shared path for a line change, a cancel and BikeBook's own "Complete" (§5.4).
 */
final class SaveSalesOrder
{
    public function __construct(private readonly HubVersions $versions) {}

    /**
     * @return array{0: WorkshopOrder, 1: bool} the order, and whether it was created
     */
    public function handle(WorkshopConnection $connection, SalesOrderInput $input, ?string $orderId = null): array
    {
        return Cache::lock("workshop:order:{$connection->id}:".hash('sha256', $input->orderNumber), 15)->block(10, function () use ($connection, $input, $orderId): array {
            return DB::transaction(function () use ($connection, $input, $orderId): array {
                $order = $orderId !== null
                    ? WorkshopOrder::query()->where('connection_id', $connection->id)->whereKey($orderId)->lockForUpdate()->first()
                    : WorkshopOrder::query()->where('connection_id', $connection->id)->where('order_number', $input->orderNumber)->lockForUpdate()->first();

                if ($orderId !== null && $order === null) {
                    throw ConnectorException::notFound('No sales order with this id for this shop.');
                }

                if ($order === null) {
                    return [$this->create($connection, $input), true];
                }

                if ($order->order_number !== $input->orderNumber && WorkshopOrder::query()->where('connection_id', $connection->id)->where('order_number', $input->orderNumber)->exists()) {
                    throw ConnectorException::invalid("Another sales order already has orderNumber {$input->orderNumber}.");
                }

                if ($order->content_hash === $input->hash() && $order->status === WorkshopOrderStatus::Open) {
                    return [$order, false];
                }

                TillOrderState::ensureUnlocked($order);

                return [$this->replace($order, $input, ['status' => WorkshopOrderStatus::Open->value, 'cancelled_at' => null]), false];
            });
        });
    }

    /**
     * A new version with these header changes (status, paid elsewhere…) and the order's lines or new ones.
     *
     * @param  array<string, mixed>  $changes
     * @param  list<SalesOrderLineInput>|null  $lines
     */
    public function mutate(WorkshopOrder $order, array $changes, ?array $lines = null): WorkshopOrder
    {
        $order->forceFill($changes);

        if ($lines !== null) {
            $this->writeLines($order, $lines);
            $order->goods_total = SalesOrderInput::totalOf($lines, $order->discount_type, $order->discount_value);
        }

        if (! $order->isDirty() && $lines === null) {
            return $order;
        }

        return $this->bump($order);
    }

    private function create(WorkshopConnection $connection, SalesOrderInput $input): WorkshopOrder
    {
        $order = new WorkshopOrder;
        $order->forceFill([
            ...$input->header,
            'company_id' => $connection->company_id,
            'branch_id' => $connection->branch_id,
            'connection_id' => $connection->id,
            'order_number' => $input->orderNumber,
            'reference' => $this->reference($connection, $input),
            'status' => WorkshopOrderStatus::Open->value,
            'version' => 1,
            'content_hash' => $input->hash(),
            'hub_version' => null,
        ])->save();

        $this->writeLines($order, $input->lines);
        $this->stampAfterCommit($order);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function replace(WorkshopOrder $order, SalesOrderInput $input, array $changes): WorkshopOrder
    {
        $order->forceFill([...$input->header, ...$changes, 'order_number' => $input->orderNumber, 'content_hash' => $input->hash()]);
        $this->writeLines($order, $input->lines);

        return $this->bump($order);
    }

    private function bump(WorkshopOrder $order): WorkshopOrder
    {
        $order->version = $order->version + 1;
        $order->hub_version = null;
        $order->save();
        $this->stampAfterCommit($order);

        return $order;
    }

    /**
     * Replaces the lines, keeping our ids for lines that match by BikeBook's line id, else by position.
     *
     * @param  list<SalesOrderLineInput>  $lines
     */
    private function writeLines(WorkshopOrder $order, array $lines): void
    {
        $existing = WorkshopOrderLine::query()->where('workshop_order_id', $order->id)->orderBy('position')->get();
        $byExternal = $existing->filter(fn (WorkshopOrderLine $l) => $l->external_line_id !== '')->keyBy('external_line_id');
        $kept = [];

        foreach ($lines as $i => $line) {
            $match = $line->externalLineId !== '' ? $byExternal->get($line->externalLineId) : null;
            $match ??= $line->externalLineId === '' ? $existing->get($i) : null;
            $match = $match instanceof WorkshopOrderLine && ! in_array($match->id, $kept, true) ? $match : new WorkshopOrderLine;
            $match->forceFill([...$line->columns(), 'company_id' => $order->company_id, 'workshop_order_id' => $order->id, 'position' => $i + 1])->save();
            $kept[] = $match->id;
        }

        WorkshopOrderLine::query()->where('workshop_order_id', $order->id)->whereNotIn('id', $kept === [] ? ['-'] : $kept)->delete();
        $order->unsetRelation('lines');
    }

    /** `WS-{job number}`, unique in the shop (a suffix when another order already has it). */
    private function reference(WorkshopConnection $connection, SalesOrderInput $input): string
    {
        $job = preg_replace('/[^A-Za-z0-9\-]/', '', (string) $input->header['external_job_number']) ?: substr(hash('sha256', $input->orderNumber), 0, 8);
        $base = 'WS-'.mb_substr($job, 0, 30);
        $reference = $base;

        for ($n = 2; WorkshopOrder::query()->where('branch_id', $connection->branch_id)->where('reference', $reference)->exists(); $n++) {
            $reference = "{$base}-{$n}";
        }

        return $reference;
    }

    private function stampAfterCommit(WorkshopOrder $order): void
    {
        $companyId = $order->company_id;
        $id = $order->id;
        DB::afterCommit(fn () => $this->versions->stampTables($companyId, [['workshop_orders', [$id]]]));
    }
}
