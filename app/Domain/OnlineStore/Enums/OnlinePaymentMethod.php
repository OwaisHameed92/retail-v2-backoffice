<?php

namespace App\Domain\OnlineStore\Enums;

/**
 * How a customer pays for an online order (OS-9). Collection: pay at the shop and/or card online; delivery: card
 * online and/or pay on delivery. Card online needs the store's DNA Payments credentials (PaymentOptions).
 */
enum OnlinePaymentMethod: string
{
    case PayAtShop = 'payAtShop';
    case CardOnline = 'cardOnline';
    case PayOnDelivery = 'payOnDelivery';

    public function label(): string
    {
        return match ($this) {
            self::PayAtShop => 'Pay at the shop',
            self::CardOnline => 'Card online',
            self::PayOnDelivery => 'Pay on delivery',
        };
    }

    /**
     * The ways to pay a method can offer.
     *
     * @return list<self>
     */
    public static function allowedFor(FulfilmentMethod $method): array
    {
        return match ($method) {
            FulfilmentMethod::Collection => [self::PayAtShop, self::CardOnline],
            FulfilmentMethod::Delivery => [self::CardOnline, self::PayOnDelivery],
        };
    }

    /** What a method offers until the business chooses: S1's pay at the shop; pay on delivery. */
    public static function defaultFor(FulfilmentMethod $method): self
    {
        return $method === FulfilmentMethod::Collection ? self::PayAtShop : self::PayOnDelivery;
    }
}
