<?php

namespace App\Http\Support\Store;

use App\Domain\OnlineStore\Data\CollectionSlot;
use App\Domain\OnlineStore\Data\DeliveryQuote;
use App\Domain\OnlineStore\Enums\FulfilmentMethod;
use App\Domain\OnlineStore\Enums\OnlinePaymentMethod;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Queries\CollectionDays;
use App\Domain\OnlineStore\Queries\CollectionSlots;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\OnlineStore\Support\PaymentOptions;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\Money;
use App\Http\Requests\Store\PlaceStoreOrderRequest;

/**
 * The public checkout's props (OS-2, OS-9): the basket, the shop, collect or delivery (delivery only when the shop
 * delivers and has a way to pay for it), the delivery quote for the postcode chosen (DeliveryQuote, worked out on
 * the server), the days and slots of the chosen method, the ways to pay it, and the totals. Everything is priced
 * again when the order is placed (PlaceCollectOrder).
 */
final class CheckoutPage
{
    public function __construct(private readonly CollectionDays $days, private readonly CollectionSlots $slots) {}

    /**
     * @return array<string, mixed>
     */
    public function for(StoreContext $context, ?string $askedDay): array
    {
        $shop = $context->shop();
        $priced = $context->priced();
        $choice = $context->checkoutChoice();
        $deliveryOffered = $shop !== null && self::deliveryOffered($context, $shop);
        $method = $deliveryOffered ? $choice['method'] : FulfilmentMethod::Collection;
        $quote = $shop !== null && $deliveryOffered ? DeliveryQuote::for($shop, $choice['postcode'], $priced->total) : null;
        $fee = $method === FulfilmentMethod::Delivery && $quote?->covered === true ? $quote->fee : '0.00';
        [$dayList, $selected, $slots] = $this->slotsFor($shop, $method, $askedDay);

        return [
            'lines' => StorePresenter::basketLines($context->store, $priced),
            'shop' => $shop === null ? null : StorePresenter::shop($shop),
            'shops' => $context->shops->count() > 1 ? $context->shops->map(fn ($s) => StorePresenter::shop($s))->values()->all() : [],
            'method' => $method->value,
            'delivery' => $quote?->toArray(),
            'days' => $dayList,
            'day' => $selected,
            'slots' => $slots,
            'payments' => self::payments($context, $method, $priced->estimated()),
            'totals' => ['items' => $priced->total, 'delivery' => $fee, 'grand' => Money::add($priced->total, $fee, 2)],
            'address' => self::addressFormat(),
            'urls' => [
                'place' => route('store.checkout.place', [], false),
                'shop' => route('store.shop', [], false),
                'method' => route('store.checkout.method', [], false),
            ],
            'honeypot' => PlaceStoreOrderRequest::HONEYPOT,
        ];
    }

    /** The shop delivers and the store has a way to pay for a delivery. */
    public static function deliveryOffered(StoreContext $context, OnlineStoreShop $shop): bool
    {
        return $shop->delivers() && PaymentOptions::for($context->store, FulfilmentMethod::Delivery) !== [];
    }

    /**
     * The method's days, the day shown (the one asked for, else the first with room) and its slots.
     *
     * @return array{0: list<array<string, mixed>>, 1: string|null, 2: list<array<string, mixed>>}
     */
    private function slotsFor(?OnlineStoreShop $shop, FulfilmentMethod $method, ?string $asked): array
    {
        if ($shop === null) {
            return [[], null, []];
        }

        $days = $this->days->days($shop, null, $method);
        $selected = $asked !== null && collect($days)->firstWhere('date', $asked) !== null ? $asked : null;

        if ($selected === null) {
            $next = $this->days->next($shop, null, $method);
            $selected = $next?->from->setTimezone(OnlineStoreSwitch::timezone())->format('Y-m-d') ?? ($days[0]['date'] ?? null);
        }

        $slots = $selected === null ? [] : array_map(fn (CollectionSlot $slot) => $slot->toArray(), $this->slots->handle($shop, $selected, null, $method));

        return [$days, $selected, $slots];
    }

    /**
     * The ways to pay the method offers now; card online is shown but not choosable for items sold by weight.
     *
     * @return list<array{value: string, label: string, hint: string, available: bool}>
     */
    private static function payments(StoreContext $context, FulfilmentMethod $method, bool $weighed): array
    {
        return array_map(fn (OnlinePaymentMethod $p) => [
            'value' => $p->value,
            'label' => $p->label(),
            'hint' => match ($p) {
                OnlinePaymentMethod::PayAtShop => 'Card or cash when you collect. Nothing to pay online.',
                OnlinePaymentMethod::PayOnDelivery => 'Pay the driver by card or cash when your order arrives.',
                OnlinePaymentMethod::CardOnline => $weighed
                    ? 'Not for items sold by weight: they are priced on the scale.'
                    : 'Pay now on our secure card page (DNA Payments).',
            },
            'available' => $p !== OnlinePaymentMethod::CardOnline || ! $weighed,
        ], PaymentOptions::for($context->store, $method));
    }

    /**
     * @return array{postcodeLabel: string, postcodeExample: string}
     */
    private static function addressFormat(): array
    {
        $address = app(Country::class)->address();

        return ['postcodeLabel' => $address['postcodeLabel'], 'postcodeExample' => $address['postcodeExample']];
    }
}
