<?php

namespace App\Domain\OnlineStore\Enums;

/**
 * What the shop did with one line of an online order while picking it (audit OSA-18, ChangeOrderLine). No value =
 * as ordered. `changed`: a different quantity (the ordered one is kept in `ordered_quantity`); `unavailable`: not
 * supplied (the line total becomes 0). The order's totals follow.
 */
enum OrderLineOutcome: string
{
    case Changed = 'changed';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Changed => 'Quantity changed',
            self::Unavailable => 'Not available',
        };
    }
}
