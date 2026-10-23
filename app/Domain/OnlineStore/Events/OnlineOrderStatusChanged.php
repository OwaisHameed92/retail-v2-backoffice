<?php

namespace App\Domain\OnlineStore\Events;

use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Models\OnlineOrder;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An online order moved on (ChangeOnlineOrderStatus), dispatched after it was committed. Listener:
 * SendOnlineOrderStatusMail (emails the customer when it is ready to collect or cancelled).
 */
final class OnlineOrderStatusChanged
{
    use Dispatchable;

    public function __construct(public readonly OnlineOrder $order, public readonly OnlineOrderStatus $from) {}
}
