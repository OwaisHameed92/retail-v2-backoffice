<?php

namespace App\Http\Controllers\Store;

use App\Domain\OnlineStore\Actions\PlaceBasketOrder;
use App\Domain\OnlineStore\Actions\StartCardPayment;
use App\Domain\OnlineStore\Enums\FulfilmentMethod;
use App\Domain\OnlineStore\Enums\OnlineOrderStatus;
use App\Domain\OnlineStore\Support\CheckoutChallenge;
use App\Domain\OnlineStore\Support\DeliveryArea;
use App\Domain\OnlineStore\Support\StoreLinks;
use App\Domain\Shared\Country\Country;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\CheckoutMethodRequest;
use App\Http\Requests\Store\PlaceStoreOrderRequest;
use App\Http\Support\Store\CheckoutPage;
use App\Http\Support\Store\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The public store's checkout (OS-2, OS-9): `GET checkout` (shop, collect or delivery, the delivery postcode check,
 * day and slot, the customer's details, the ways to pay: CheckoutPage), `POST checkout/method` (collect or delivery
 * and the postcode, kept in the session) and `POST checkout`, which places the session basket (PlaceBasketOrder →
 * PlaceCollectOrder). Paid at the shop or on delivery: the basket empties and the order's signed page confirms it.
 * Card online: the order waits for its payment and the customer goes to DNA's page (StartCardPayment); the basket is
 * kept until DNA confirms the payment (StoreOrderPage).
 */
class StoreCheckoutController extends Controller
{
    public const PLACED = 'online_store.placed';

    public function show(Request $request, StoreContext $context, CheckoutPage $page): Response
    {
        $day = $request->query('day');

        return Inertia::render('store/checkout', [
            ...$page->for($context, is_string($day) ? $day : null),
            'challenge' => CheckoutChallenge::issue($context->store->id),
        ]);
    }

    public function method(CheckoutMethodRequest $request, StoreContext $context): RedirectResponse
    {
        $method = FulfilmentMethod::from((string) $request->validated('fulfilment'));
        $postcode = trim((string) $request->validated('postcode'));
        $code = $postcode === '' ? null : DeliveryArea::postcode($postcode);

        if ($postcode !== '' && $code === null) {
            $context->chooseCheckout($method, null);

            throw ValidationException::withMessages(['deliveryPostcode' => 'Enter a full postcode, like '.app(Country::class)->address()['postcodeExample'].'.']);
        }

        $context->chooseCheckout($method, $code);

        return back();
    }

    public function place(PlaceStoreOrderRequest $request, StoreContext $context, PlaceBasketOrder $place, StoreLinks $links, StartCardPayment $card): HttpResponse
    {
        $shop = $context->shops->firstWhere('id', (string) $request->validated('shopId'))
            ?? throw ValidationException::withMessages(['shopId' => 'Choose one of the shops listed.']);

        $context->chooseShop($shop);
        $placed = $place->handle($context->store, $shop, $context->priced(), $request->details());

        if ($placed->order->status === OnlineOrderStatus::AwaitingPayment) {
            $context->rememberPaying($placed->order->id);

            return Inertia::location($card->handle($context->store, $placed->order));
        }

        $context->forgetBasket();
        $request->session()->flash(self::PLACED, $placed->order->id);

        return redirect()->to($links->orderPage($placed->order));
    }
}
