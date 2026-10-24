<?php

namespace App\Domain\OnlineStore\Data;

use App\Domain\OnlineStore\Models\OnlineOrder;

/**
 * The result of PlaceCollectOrder: the order with its lines. The customer's order page is a link signed over the
 * order's id (StoreLinks::orderPage / orderLink), so there is no token to give out (audit OSA-27).
 */
final readonly class PlacedOrder
{
    public function __construct(public OnlineOrder $order) {}
}
