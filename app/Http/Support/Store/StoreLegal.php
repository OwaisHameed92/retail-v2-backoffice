<?php

namespace App\Http\Support\Store;

use App\Domain\OnlineStore\Enums\FulfilmentMethod;
use App\Domain\OnlineStore\Enums\OnlinePaymentMethod;
use App\Domain\OnlineStore\Legal\LegalFacts;
use App\Domain\OnlineStore\Legal\LegalPage;
use App\Domain\OnlineStore\Legal\LegalPages;
use App\Domain\OnlineStore\Legal\StoreTrader;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Support\PaymentOptions;
use App\Domain\OnlineStore\Support\StoreScope;

/**
 * The public store's legal side (OS-11, audit OSA-2): the trader details every page's footer shows (StoreTrader, the
 * store's own, else the business record's), the links to the legal pages, and one legal page's document with the
 * store's own extra text (LegalPages, filled from what the store offers: its collection shops, delivery and ways to pay).
 */
final class StoreLegal
{
    public static function trader(StoreContext $context): StoreTrader
    {
        return StoreTrader::for($context->store, StoreScope::company($context->store));
    }

    /**
     * @return array<string, string> LegalPage value => its address on this store
     */
    public static function urls(): array
    {
        $urls = [];

        foreach (LegalPage::cases() as $page) {
            $urls[$page->value] = route('store.'.$page->value, [], false);
        }

        return $urls;
    }

    /**
     * @return array{page: string, title: string, intro: string, sections: list<array{heading: string, paragraphs: list<string>}>, extra: string|null}
     */
    public static function page(StoreContext $context, LegalPage $page): array
    {
        $extra = $context->store->legal_extra[$page->value] ?? null;

        return ['page' => $page->value, ...LegalPages::document($page, self::facts($context)), 'extra' => is_string($extra) && trim($extra) !== '' ? $extra : null];
    }

    public static function facts(StoreContext $context): LegalFacts
    {
        $store = $context->store;
        $delivery = $context->shops->contains(fn (OnlineStoreShop $shop) => (bool) $shop->delivery_enabled);

        return new LegalFacts(
            trader: self::trader($context),
            shops: $context->shops->map(function (OnlineStoreShop $shop): array {
                $info = StorePresenter::shop($shop->loadMissing('branch'));

                return ['name' => (string) $info['name'], 'address' => $info['address'] !== '' ? (string) $info['address'] : null];
            })->values()->all(),
            delivery: $delivery,
            card: PaymentOptions::offers($store, FulfilmentMethod::Collection, OnlinePaymentMethod::CardOnline)
                || ($delivery && PaymentOptions::offers($store, FulfilmentMethod::Delivery, OnlinePaymentMethod::CardOnline)),
            payAtShop: PaymentOptions::offers($store, FulfilmentMethod::Collection, OnlinePaymentMethod::PayAtShop),
            payOnDelivery: $delivery && PaymentOptions::offers($store, FulfilmentMethod::Delivery, OnlinePaymentMethod::PayOnDelivery),
            anonymiseMonths: max(0, (int) config('online_store.anonymise_after_months', 24)),
        );
    }
}
