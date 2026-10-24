<?php

namespace App\Http\Support\Store;

use App\Domain\OnlineStore\Actions\ReleaseUnpaidOrder;
use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\OnlineStore\Support\StoreScope;
use App\Http\Controllers\Store\StoreCheckoutController;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The customer's order page (OS-2, OS-9), opened by its signed link (StoreLinks). Right after checkout it is the
 * confirmation. For a card order (OS-9) it is also where DNA sends the customer back: while DNA's result is on its
 * way it says so (the page asks again every few seconds); coming back with `payment=failed` gives the slot back
 * (ReleaseUnpaidOrder: the payment can only be recorded by DNA's own callback, never by this page) and keeps the
 * basket for another try. Once a card order is paid the basket is emptied and the confirmation shown.
 */
final class StoreOrderPage
{
    public static function render(Request $request, StoreContext $context, OnlineOrder $order): Response
    {
        if ($request->query('payment') === 'failed' && $order->status === OnlineOrderStatus::AwaitingPayment) {
            app(ReleaseUnpaidOrder::class)->handle($context->store, $order->id, 'Customer came back from the card page without paying');
            $order->refresh();
        }

        $justPlaced = $request->session()->get(StoreCheckoutController::PLACED) === $order->id;

        if ($context->payingOrder() === $order->id && ! $order->status->isPaymentPending()) {
            $context->forgetBasket();
            $context->rememberPaying(null);
            $justPlaced = true;
        }

        $props = StoreScope::run($context->store, function () use ($order, $context, $justPlaced): array {
            $order->loadMissing(['lines', 'shop.branch', 'branch']);

            return [
                'order' => StoreOrderPresenter::order($order),
                'shop' => StorePresenter::shop($order->shop),
                'justPlaced' => $justPlaced,
                'retryUrl' => $order->status === OnlineOrderStatus::PaymentFailed && $context->payingOrder() === $order->id
                    ? route('store.checkout', ['slug' => $context->store->slug], false) : null,
            ];
        });

        return Inertia::render('store/order', $props)->toResponse($request);
    }
}
