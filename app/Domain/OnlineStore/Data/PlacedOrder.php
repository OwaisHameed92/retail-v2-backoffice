<?php

namespace App\Domain\OnlineStore\Data;

use App\Domain\OnlineStore\Models\OnlineOrder;

/**
 * The result of PlaceCollectOrder: the order with its lines and the order page's plain token. The token is given
 * out only here, once; only its hash is stored.
 */
final readonly class PlacedOrder
{
    public function __construct(public OnlineOrder $order, public string $token) {}
}
