<?php

namespace App\Http\Controllers\Store;

use App\Domain\OnlineStore\Queries\StoreOrderById;
use App\Http\Controllers\Controller;
use App\Http\Support\Store\StoreContext;
use App\Http\Support\Store\StoreOrderPage;
use App\Http\Support\Store\StorePages;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /{slug}/order/{order}` (OS-2): the order page by the store's address, kept so links sent before the order-id
 * links (StoreOrderLinkController, audit OSA-6) keep working while the store is live at that address. No signature
 * or a changed link → the store's 403; another store's or business's order → 404.
 */
class StoreOrderController extends Controller
{
    public function __invoke(Request $request, StoreContext $context, StoreOrderById $orders, string $order): Response
    {
        if (! $request->hasValidSignature()) {
            return StorePages::error($request, 403, $context);
        }

        $found = $orders->handle($context->store, $order);

        return $found === null ? StorePages::error($request, 404, $context) : StoreOrderPage::render($request, $context, $found);
    }
}
