<?php

namespace App\Domain\OnlineStore\Enums;

/**
 * How an online order reaches the customer (OS-9): collected at the shop (S1, the default) or delivered locally by
 * the shop. Delivery is offered only by a shop that turned it on and covers the customer's postcode.
 */
enum FulfilmentMethod: string
{
    case Collection = 'collection';
    case Delivery = 'delivery';

    public function label(): string
    {
        return match ($this) {
            self::Collection => 'Click & collect',
            self::Delivery => 'Delivery',
        };
    }
}
