<?php

namespace App\Http\Controllers\Store;

use App\Domain\OnlineStore\Queries\StoreOrderById;
use App\Http\Controllers\Controller;
use App\Http\Support\Store\StoreContext;
use App\Http\Support\Store\StoreOrderPresenter;
use App\Http\Support\Store\StorePages;
use App\Http\Support\Store\StorePresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /{slug}/order/{order}` (OS-2): the customer's order page, opened only by its signed link (StoreLinks, in the
 * confirmation redirect and the emails). No signature or a changed link → the store's 403; another store's or
 * business's order → 404. Right after checkout it opens as the confirmation ("your order is in").
 */
class StoreOrderController extends Controller
{
    public function __invoke(Request $request, StoreContext $context, StoreOrderById $orders, string $order): Response
    {
        if (! $request->hasValidSignature()) {
            return StorePages::error($request, 403, $context);
        }

        $found = $orders->handle($context->store, $order);

        if ($found === null) {
            return StorePages::error($request, 404, $context);
        }

        return Inertia::render('store/order', [
            'order' => StoreOrderPresenter::order($found),
            'shop' => StorePresenter::shop($found->shop),
            'justPlaced' => $request->session()->get(StoreCheckoutController::PLACED) === $found->id,
        ])->toResponse($request);
    }
}
