<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Enums\OrderLineOutcome;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Models\OnlineOrderLine;
use App\Domain\OnlineStore\Support\OrderLineRules;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Staff change one line of an open online order while picking it (audit OSA-18, portal → order page): mark it not
 * available (its total becomes 0), change its quantity (a weighed line in 0.25 kg steps, others whole numbers), or
 * put it back as ordered. The quantity first ordered is kept. The order's item and grand totals follow (the delivery
 * fee stays). An order paid by card can only go down: the difference is refunded in the shop's DNA portal (no refund
 * API in S2). Locked; audited. The till still rings up what is handed over.
 */
final class ChangeOrderLine
{
    public const RESTORE = 'restore';

    public function __construct(private readonly RecordAudit $audit, private readonly CurrentCompany $tenancy) {}

    /**
     * @param  string  $action  "unavailable", "changed" or "restore"
     *
     * @throws ValidationException
     */
    public function handle(Company $company, string $orderId, string $lineId, string $action, ?string $quantity = null): OnlineOrder
    {
        return $this->tenancy->runAs($company, fn (): OnlineOrder => DB::transaction(function () use ($company, $orderId, $lineId, $action, $quantity): OnlineOrder {
            $order = OnlineOrder::query()->where('company_id', $company->id)->whereKey($orderId)->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['order' => 'That order was not found.']);
            $line = OnlineOrderLine::query()->where('company_id', $company->id)->where('online_order_id', $order->id)->whereKey($lineId)->first()
                ?? throw ValidationException::withMessages(['line' => 'That item is not on this order.']);

            if (! in_array($order->status->value, ['new', 'picking', 'ready'], true)) {
                throw ValidationException::withMessages(['line' => 'Items can be changed only before the order is handed over.']);
            }

            $ordered = $line->ordered_quantity ?? $line->quantity;
            $before = ['outcome' => $line->outcome?->value, 'quantity' => $line->quantity, 'line_total' => $line->line_total];
            [$outcome, $qty] = match ($action) {
                OrderLineOutcome::Unavailable->value => [OrderLineOutcome::Unavailable, '0'],
                OrderLineOutcome::Changed->value => [OrderLineOutcome::Changed, self::quantity($line, (string) $quantity)],
                self::RESTORE => [null, $ordered],
                default => throw ValidationException::withMessages(['action' => 'Choose what to do with the item.']),
            };

            if ($outcome === OrderLineOutcome::Changed && Money::equals($qty, $ordered)) {
                $outcome = null;
            }

            $line->forceFill([
                'outcome' => $outcome,
                'ordered_quantity' => $outcome === null ? null : $ordered,
                'quantity' => $outcome === OrderLineOutcome::Unavailable ? $ordered : $qty,
                'line_total' => $outcome === OrderLineOutcome::Unavailable ? '0.00' : Money::mul($line->unit_price, $qty, 2),
            ])->save();

            $items = Money::sum($order->lines()->get()->pluck('line_total')->all(), 2);
            $grand = Money::add($items, $order->delivery_fee, 2);

            if ($order->isPaid() && Money::compare($grand, (string) $order->paid_amount) > 0) {
                throw ValidationException::withMessages(['quantity' => 'This order was paid by card online, so it can only go down. Add anything extra at the till.']);
            }

            $order->forceFill(['items_total' => $items, 'grand_total' => $grand])->save();

            $this->audit->handle('online_order.line_changed', $order, $before, [
                'outcome' => $line->outcome?->value, 'quantity' => $line->quantity, 'line_total' => $line->line_total, 'grand_total' => $grand,
            ], ['number' => $order->reference, 'item' => $line->name], companyId: $company->id);

            return $order->load(['lines', 'branch']);
        }));
    }

    /**
     * @throws ValidationException
     */
    private static function quantity(OnlineOrderLine $line, string $quantity): string
    {
        $quantity = trim($quantity);
        $max = $line->is_weighed ? OrderLineRules::MAX_WEIGHED_KG : (string) OrderLineRules::MAX_UNITS;
        $valid = preg_match('/^\d{1,6}(\.\d{1,4})?$/', $quantity) === 1;
        $step = $valid && ($line->is_weighed ? Money::isZero(bcmod(Money::mul($quantity, '4', 4), '1', 4)) : Money::isZero(bcmod($quantity, '1', 4)));

        if (! $step || Money::compare($quantity, '0') <= 0 || Money::compare($quantity, $max) > 0) {
            throw ValidationException::withMessages(['quantity' => $line->is_weighed ? 'Enter the weight in steps of 0.25 kg.' : 'Enter a whole number of items.']);
        }

        return Money::normalise($quantity, 4);
    }
}
