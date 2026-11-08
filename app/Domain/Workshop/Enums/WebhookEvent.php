<?php

namespace App\Domain\Workshop\Enums;

/** The events BikeBook may subscribe to (WORKSHOP-BIKEBOOK.md §3.8). */
enum WebhookEvent: string
{
    case OrderStatusChanged = 'order.statuschanged';
    case InventoryUpdated = 'inventory.updated';
    case ProductUpdated = 'product.updated';
    case CustomerUpdated = 'customer.updated';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $event) => $event->value, self::cases());
    }
}
