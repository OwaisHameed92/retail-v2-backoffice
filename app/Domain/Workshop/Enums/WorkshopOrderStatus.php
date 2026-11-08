<?php

namespace App\Domain\Workshop\Enums;

/** `WorkshopOrder.status` as the portal sends it (WORKSHOP-BIKEBOOK.md §5.1; the till's own `collected` is never sent). */
enum WorkshopOrderStatus: string
{
    case Open = 'open';
    case Cancelled = 'cancelled';
}
