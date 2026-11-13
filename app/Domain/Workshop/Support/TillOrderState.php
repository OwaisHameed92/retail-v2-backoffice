<?php

namespace App\Domain\Workshop\Support;

use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Enums\CustomerOrderPaymentKind;
use App\Domain\TillData\Enums\CustomerOrderStatus;
use App\Domain\Workshop\Models\WorkshopOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the shop's till did with a workshop order, from its pushed `CustomerOrder` (same id as the WorkshopOrder, or
 * `externalOrderId` = it, `externalProvider` bikebook) and `CustomerOrderPayment` rows (WORKSHOP-BIKEBOOK.md §3.7,
 * §5.3). Only orders we sent are ever looked up, so a Direct-mode order (an `externalOrderId` we never sent) is
 * never reported by the portal (one reporter per order, §3.8).
 */
final readonly class TillOrderState
{
    private function __construct(
        public ?string $customerOrderId,
        public ?string $status,
        public string $paidTotal,
        public string $refundedTotal,
        public bool $paymentTaken,
        public ?string $saleId,
        public ?CarbonImmutable $paidAt,
    ) {}

    public static function of(WorkshopOrder $order): self
    {
        $row = DB::table('customer_orders')->where('company_id', $order->company_id)->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('id', $order->id)->orWhere(fn ($e) => $e->where('external_order_id', $order->id)->where('external_provider', 'bikebook')))
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$order->id])
            ->first(['id', 'status', 'paid_total', 'sale_id', 'collected_at', 'updated_at']);

        if ($row === null) {
            return new self(null, null, '0.00', '0.00', false, null, null);
        }

        $payments = DB::table('customer_order_payments')->where('company_id', $order->company_id)->where('order_id', $row->id)->whereNull('deleted_at')
            ->get(['kind', 'amount', 'sale_id', 'at']);
        $refunds = $payments->where('kind', CustomerOrderPaymentKind::Refund->value);
        $refunded = Money::sum($refunds->map(fn ($p) => ltrim((string) $p->amount, '-'))->all());
        $at = $row->collected_at ?? $payments->where('kind', '!=', CustomerOrderPaymentKind::Refund->value)->max('at');

        return new self(
            customerOrderId: (string) $row->id,
            status: $row->status === null ? null : (string) $row->status,
            paidTotal: Money::normalise($row->paid_total ?? '0'),
            refundedTotal: $refunded,
            paymentTaken: $payments->isNotEmpty() || Money::compare($row->paid_total ?? '0', '0') > 0 || $row->sale_id !== null,
            saleId: $row->sale_id === null ? ($payments->first()?->sale_id === null ? null : (string) $payments->first()->sale_id) : (string) $row->sale_id,
            paidAt: $at === null ? null : CarbonImmutable::parse((string) $at, 'UTC'),
        );
    }

    /**
     * The till has pushed its CustomerOrder for it, in any status (till team reply 2026-11-13, §5.0): the till makes
     * that order the moment staff open the job on a sale, before any money, and from then on refuses every change. So
     * the portal refuses BikeBook's changes from then on too, else a 200 here would be refused at the till.
     */
    public function locked(): bool
    {
        return $this->customerOrderId !== null;
    }

    /** BikeBook's status (§3.7), or null while only the WorkshopOrder decides. */
    public function bikeBookStatus(): ?string
    {
        return match ($this->status) {
            CustomerOrderStatus::Collected->value => 'Complete',
            CustomerOrderStatus::Cancelled->value, CustomerOrderStatus::Expired->value => 'Cancelled',
            null => null,
            default => 'Saved',
        };
    }

    /**
     * Throws 409 order_locked when the till has started (and records when it was first seen).
     */
    public static function ensureUnlocked(WorkshopOrder $order): void
    {
        if ($order->locked_at === null && ! self::of($order)->locked()) {
            return;
        }

        if ($order->locked_at === null) {
            $order->forceFill(['locked_at' => CarbonImmutable::now('UTC')])->save();
        }

        throw ConnectorException::locked();
    }
}
