<?php

namespace App\Http\Support\Store;

use App\Domain\OnlineStore\Enums\FulfilmentMethod;
use App\Domain\OnlineStore\Enums\OnlinePaymentMethod;
use App\Domain\OnlineStore\Enums\StoreTemplate;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Support\PaymentOptions;
use App\Domain\OnlineStore\Support\ShopMethods;

/**
 * OS-12: the public store's words that depend on how it gets orders to customers (ShopMethods mode: collection,
 * delivery or both): the top strip, the hero's chip and default text, "how it works", the shops section, the footer
 * line, the shop chooser, the basket and product hints and the "about" facts. Collection only keeps the words the
 * store had before OS-12; delivery only never says "click & collect". Shared with every store page as
 * `store.wording` (StorePages); the React pages draw these words, they do not make them up.
 */
final class StoreWording
{
    /**
     * @return array<string, mixed>
     */
    public static function for(StoreContext $context): array
    {
        $store = $context->store;
        $shop = $context->shop();
        $shop?->loadMissing('branch');
        $name = $shop === null ? null : (string) StorePresenter::shop($shop)['name'];

        return self::make(ShopMethods::mode($store, $context->shops), $store->template, $name, $shop?->prep_minutes, self::deliveryPay($store), $context->shops->count());
    }

    /**
     * @return array<string, mixed>
     */
    public static function make(string $mode, StoreTemplate $template, ?string $shopName, ?int $prepMinutes, string $deliveryPay, int $shopCount = 1): array
    {
        $butcher = $template === StoreTemplate::Butcher;
        $at = $shopName === null ? '' : " at {$shopName}";
        $from = $shopName === null ? '' : " from {$shopName}";
        $prep = $prepMinutes ?? 30;
        $collect = $mode === ShopMethods::COLLECTION;
        $delivery = $mode === ShopMethods::DELIVERY;

        return [
            'mode' => $mode,
            'chip' => $collect ? 'Click & collect' : ($delivery ? 'Local delivery' : 'Collect or delivery'),
            'strip' => $collect ? "Order online, pay when you collect{$at}"
                : ($delivery ? "Order online, delivered to your door{$from}" : "Order online: collect{$at} or have it delivered"),
            'heroText' => self::heroText($mode, $butcher, $from, $prepMinutes),
            'how' => self::how($mode, $butcher, $prep, $deliveryPay),
            'where' => match ($mode) {
                ShopMethods::DELIVERY => ['title' => 'Where we deliver', 'sub' => 'Delivery areas, fees and hours'],
                ShopMethods::BOTH => $shopCount > 1
                    ? ['title' => 'Our shops', 'sub' => 'Collect from the shop nearest you, or have it delivered']
                    : ['title' => 'Collect or get it delivered', 'sub' => 'Opening hours for collection, and where we deliver'],
                default => $shopCount > 1 ? ['title' => 'Our shops', 'sub' => 'Collect from the shop nearest you'] : ['title' => 'Where to collect', 'sub' => 'Opening hours for collection'],
            },
            'footer' => match (true) {
                $butcher && $delivery => 'Cut to order by your local butcher. Order online, we weigh it and deliver it to your door, and you pay the real weight.',
                $butcher => 'Cut to order by your local butcher. Order online, we weigh it at the counter and you pay the real weight.',
                $delivery => 'Your local shop, online. Order from the shelves you know, at the shelf price, delivered to your door.',
                default => 'Your local shop, online. Order from the shelves you know, at the shelf price, ready when you are.',
            },
            'helpLink' => $collect ? 'Collection' : ($delivery ? 'Delivery areas' : 'Delivery & collection'),
            'hoursTitle' => $delivery ? 'Delivery hours' : 'Opening hours',
            'shopLabel' => $collect ? 'Collect from' : ($delivery ? 'Delivery from' : 'Your shop'),
            'shopHint' => $collect ? 'Prices and stock follow the shop you collect from.' : 'Prices and stock follow the shop you order from.',
            'basketEmpty' => $collect ? 'Add a few things and pick a time to collect them.'
                : ($delivery ? 'Add a few things and pick a time to have them delivered.' : 'Add a few things, then collect them or have them delivered.'),
            // Only the methods the store offers ("Next delivery Today 14:00–15:00" on the hero chip).
            'nextLabel' => array_filter(['collection' => $delivery ? null : 'Next collection', 'delivery' => $collect ? null : 'Next delivery']),
            'nextHint' => array_filter(['collection' => $delivery ? null : 'Next collection time', 'delivery' => $collect ? null : 'Next delivery time']),
            'payHint' => $collect ? 'Card or cash when you collect' : ($delivery ? $deliveryPay : 'At the shop, or when it is delivered'),
            'payNote' => $collect ? 'You pay at the shop when you collect.'
                : ($delivery ? $deliveryPay.'.' : 'You pay at the shop when you collect, or when it is delivered.'),
            'offerHint' => $collect ? 'Taken off at the till when you collect and pay in the shop' : 'Taken off at the till when the shop rings up your order',
            'weighedHint' => $collect ? 'price on the scale when you collect.' : 'price on the scale when we pack your order.',
            'facts' => self::facts($mode, $prep, $deliveryPay),
        ];
    }

    /** The hero's text when a banner has none. */
    private static function heroText(string $mode, bool $butcher, string $from, ?int $prepMinutes): string
    {
        $ready = $prepMinutes ? " in about {$prepMinutes} minutes" : '';

        return match ($mode) {
            ShopMethods::DELIVERY => $butcher
                ? 'Choose the cut and the weight online. We prepare it and deliver it to your door, and you pay the real weight.'
                : "Order online and we deliver it to your door{$from}. Choose a delivery time at checkout.",
            ShopMethods::BOTH => $butcher
                ? 'Choose the cut and the weight online. Collect it from the counter or have it delivered, and you pay the real weight.'
                : "Order online and collect{$from}{$ready}, or have it delivered to your door.",
            default => $butcher
                ? 'Choose the cut and the weight online. We prepare it for your collection time'.($prepMinutes ? ", ready in about {$prepMinutes} minutes" : '').', and you pay the real weight at the counter.'
                : "Order online and collect{$from}{$ready}. Pay at the counter when you pick it up.",
        };
    }

    /**
     * "How it works": the small title, the heading, the line under it and the three steps.
     *
     * @return array{eyebrow: string, title: string, lead: string, steps: list<array{title: string, text: string}>}
     */
    private static function how(string $mode, bool $butcher, int $prep, string $deliveryPay): array
    {
        $basket = $butcher
            ? ['title' => 'Choose your cut', 'text' => 'Pick the meat and the weight. Weighed cuts show an estimated price.']
            : ['title' => 'Fill your basket', 'text' => 'Everything is at the shelf price, straight from our tills.'];

        return match ($mode) {
            ShopMethods::DELIVERY => ['eyebrow' => 'Local delivery', 'title' => 'Shop online, delivered to your door',
                'lead' => 'No account needed. Check your postcode at checkout and choose a delivery time.', 'steps' => [
                    $basket,
                    ['title' => 'Pick a time', 'text' => 'Choose a delivery slot. We pack your order and bring it to your door.'],
                    ['title' => 'We deliver', 'text' => $butcher ? 'We weigh it before it leaves and you pay the real weight. '.$deliveryPay.'.' : $deliveryPay.'.'],
                ]],
            ShopMethods::BOTH => ['eyebrow' => 'Collect or delivery', 'title' => 'Shop online, your way',
                'lead' => 'No account needed. Collect from the shop or have it delivered to your door.', 'steps' => [
                    $basket,
                    ['title' => 'Pick a time', 'text' => "Choose a collection slot (ready in about {$prep} minutes) or a delivery time."],
                    ['title' => 'Collect or receive', 'text' => 'Pay at the counter when you collect, or when it is delivered. '.$deliveryPay.'.'],
                ]],
            default => ['eyebrow' => 'Click & collect', 'title' => 'Shop online, skip the queue',
                'lead' => 'No account and no card online. You pay in the shop when you collect.', 'steps' => [
                    $basket,
                    ['title' => 'Pick a time', 'text' => "Choose a collection slot. We need about {$prep} minutes to get it ready."],
                    ['title' => 'Collect and pay', 'text' => $butcher
                        ? 'We weigh it at the counter and you pay the real weight, by card or cash.'
                        : 'Give your order number at the counter and pay there, by card or cash.'],
                ]],
        };
    }

    /**
     * The "about" section's first and third facts (the second is the template's price fact).
     *
     * @return array{ready: array{big: string, title: string, text: string}, pay: array{big: string, title: string, text: string}}
     */
    private static function facts(string $mode, int $prep, string $deliveryPay): array
    {
        $ready = ['big' => "{$prep} min", 'title' => 'Ready to collect', 'text' => 'We get your order ready for the time you choose.'];

        return match ($mode) {
            ShopMethods::DELIVERY => [
                'ready' => ['big' => 'Local', 'title' => 'Delivered to you', 'text' => 'We bring your order to your door at the time you choose.'],
                'pay' => ['big' => 'Pay', 'title' => 'Your way', 'text' => $deliveryPay.'.'],
            ],
            ShopMethods::BOTH => [
                'ready' => $ready,
                'pay' => ['big' => 'Pay', 'title' => 'Your way', 'text' => 'At the counter when you collect, or when it is delivered.'],
            ],
            default => [
                'ready' => $ready,
                'pay' => ['big' => 'Pay', 'title' => 'At the counter', 'text' => 'Card or cash when you collect. Nothing to pay online.'],
            ],
        };
    }

    /** How a delivery is paid, from the ways the store offers now ("Pay the driver by card or cash when it arrives"). */
    private static function deliveryPay(OnlineStore $store): string
    {
        $options = PaymentOptions::for($store, FulfilmentMethod::Delivery);
        $driver = in_array(OnlinePaymentMethod::PayOnDelivery, $options, true);
        $card = in_array(OnlinePaymentMethod::CardOnline, $options, true);

        return match (true) {
            $driver && $card => 'Pay by card online, or pay the driver when it arrives',
            $card => 'Pay by card online when you order',
            default => 'Pay the driver by card or cash when it arrives',
        };
    }
}
