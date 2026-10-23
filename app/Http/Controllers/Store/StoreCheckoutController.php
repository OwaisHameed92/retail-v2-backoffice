<?php

namespace App\Http\Controllers\Store;

use App\Domain\OnlineStore\Actions\PlaceBasketOrder;
use App\Domain\OnlineStore\Data\CollectionSlot;
use App\Domain\OnlineStore\Queries\CollectionDays;
use App\Domain\OnlineStore\Queries\CollectionSlots;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\OnlineStore\Support\StoreLinks;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\PlaceStoreOrderRequest;
use App\Http\Support\Store\StoreContext;
use App\Http\Support\Store\StorePresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public store's checkout (OS-2), click & collect, pay at the shop: `GET checkout` (shop picker when the store
 * has more than one, day and slot from CollectionSlots, the customer's details) and `POST checkout`, which places
 * the session basket (PlaceBasketOrder → PlaceCollectOrder), empties it and goes to the order's signed page.
 */
class StoreCheckoutController extends Controller
{
    public const PLACED = 'online_store.placed';

    public function show(Request $request, StoreContext $context, CollectionDays $days, CollectionSlots $slots): Response
    {
        $shop = $context->shop();
        $dayList = $shop === null ? [] : $days->days($shop);
        $asked = (string) $request->query('day');
        $selected = collect($dayList)->firstWhere('date', $asked) !== null ? $asked : null;

        if ($shop !== null && $selected === null) {
            $next = $days->next($shop);
            $selected = $next?->from->setTimezone(OnlineStoreSwitch::timezone())->format('Y-m-d') ?? ($dayList[0]['date'] ?? null);
        }

        return Inertia::render('store/checkout', [
            'lines' => StorePresenter::basketLines($context->store, $context->priced()),
            'shop' => $shop === null ? null : StorePresenter::shop($shop),
            'shops' => $context->shops->count() > 1 ? $context->shops->map(fn ($s) => StorePresenter::shop($s))->values()->all() : [],
            'days' => $dayList,
            'day' => $selected,
            'slots' => $shop === null || $selected === null ? [] : array_map(fn (CollectionSlot $slot) => $slot->toArray(), $slots->handle($shop, $selected)),
            'urls' => ['place' => route('store.checkout.place', [], false), 'shop' => route('store.shop', [], false)],
            'honeypot' => PlaceStoreOrderRequest::HONEYPOT,
        ]);
    }

    public function place(PlaceStoreOrderRequest $request, StoreContext $context, PlaceBasketOrder $place, StoreLinks $links): RedirectResponse
    {
        $shop = $context->shops->firstWhere('id', (string) $request->validated('shopId'))
            ?? throw ValidationException::withMessages(['shopId' => 'Choose one of the shops listed.']);

        $context->chooseShop($shop);
        $placed = $place->handle($context->store, $shop, $context->priced(), $request->customer());
        $context->forgetBasket();
        $request->session()->flash(self::PLACED, $placed->order->id);

        return redirect()->to($links->orderPage($placed->order));
    }
}
