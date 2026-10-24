<?php

namespace App\Domain\OnlineStore\Enums;

/**
 * Where an online order is (docs/online-store.md "Orders"). Collection: new → picking → ready → collected; delivery
 * (OS-9): new → picking → ready → out for delivery → delivered; either cancelled (with a reason) from any open stage.
 * Collected, delivered and cancelled are final; so is "not collected" (audit OSA-21: a ready order nobody came for,
 * or a delivery that could not be made). Moved by ChangeOnlineOrderStatus only; its last move can be undone for a
 * short time (UndoOnlineOrderStatus).
 *
 * A card-online order (OS-9) starts as awaiting payment: not on the board, its slot held for a short time
 * (`payment_expires_at`). The DNA result (RecordCardPayment) moves it to new once paid, or to payment failed (the slot
 * is released). Staff never move these two.
 */
enum OnlineOrderStatus: string
{
    case AwaitingPayment = 'awaitingPayment';
    case PaymentFailed = 'paymentFailed';
    case New = 'new';
    case Picking = 'picking';
    case Ready = 'ready';
    case OutForDelivery = 'outForDelivery';
    case Collected = 'collected';
    case Delivered = 'delivered';
    case NotCollected = 'notCollected';
    case Cancelled = 'cancelled';

    public function label(?FulfilmentMethod $method = null): string
    {
        return match ($this) {
            self::AwaitingPayment => 'Awaiting payment',
            self::PaymentFailed => 'Payment not completed',
            self::New => 'New',
            self::Picking => 'Picking',
            self::Ready => $method === FulfilmentMethod::Delivery ? 'Ready to go out' : 'Ready to collect',
            self::OutForDelivery => 'Out for delivery',
            self::Collected => 'Collected',
            self::Delivered => 'Delivered',
            self::NotCollected => $method === FulfilmentMethod::Delivery ? 'Not delivered' : 'Not collected',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * The stages staff may move this one to (a collection order unless `$method` says delivery).
     *
     * @return list<self>
     */
    public function next(?FulfilmentMethod $method = null): array
    {
        $delivery = $method === FulfilmentMethod::Delivery;

        return match ($this) {
            self::New => [self::Picking, self::Cancelled],
            self::Picking => [self::Ready, self::Cancelled],
            self::Ready => $delivery ? [self::OutForDelivery, self::Cancelled] : [self::Collected, self::NotCollected, self::Cancelled],
            self::OutForDelivery => $delivery ? [self::Delivered, self::NotCollected, self::Cancelled] : [self::Cancelled],
            self::AwaitingPayment, self::PaymentFailed, self::Collected, self::Delivered, self::NotCollected, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $to, ?FulfilmentMethod $method = null): bool
    {
        return in_array($to, $this->next($method), true);
    }

    /** Still to be worked by the shop. */
    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::Picking, self::Ready, self::OutForDelivery], true);
    }

    /** Finished (collected, delivered, not collected or cancelled): in the board's Done column. */
    public function isDone(): bool
    {
        return in_array($this, [self::Collected, self::Delivered, self::NotCollected, self::Cancelled], true);
    }

    /** A card order whose payment has not gone through: never shown to staff. */
    public function isPaymentPending(): bool
    {
        return $this === self::AwaitingPayment || $this === self::PaymentFailed;
    }

    /**
     * The stages staff see (board, order page, counts).
     *
     * @return list<string>
     */
    public static function staffValues(): array
    {
        return array_values(array_map(fn (self $s) => $s->value, array_filter(self::cases(), fn (self $s) => ! $s->isPaymentPending())));
    }

    /** The order's time column set when it reaches this stage (null for new: `created_at` / `paid_at`). */
    public function timeColumn(): ?string
    {
        return match ($this) {
            self::AwaitingPayment, self::PaymentFailed, self::New => null,
            self::Picking => 'picked_at',
            self::Ready => 'ready_at',
            self::OutForDelivery => 'out_for_delivery_at',
            self::Collected => 'collected_at',
            self::Delivered => 'delivered_at',
            self::NotCollected => 'not_collected_at',
            self::Cancelled => 'cancelled_at',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
