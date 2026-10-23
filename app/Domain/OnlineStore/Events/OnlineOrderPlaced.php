<?php

namespace App\Domain\OnlineStore\Events;

use App\Domain\OnlineStore\Models\OnlineOrder;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A customer placed a click & collect order (PlaceCollectOrder), dispatched after it was committed. Listener:
 * SendNewOnlineOrderMessages (the shop's email, the portal notification and the customer's "order received" email).
 */
final class OnlineOrderPlaced
{
    use Dispatchable;

    public function __construct(public readonly OnlineOrder $order) {}
}
