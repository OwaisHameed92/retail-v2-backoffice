<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\OnlineStore\Enums\FulfilmentMethod;
use App\Domain\OnlineStore\Enums\OnlinePaymentMethod;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Payments\DnaCredentials;

/**
 * The ways to pay a store offers for each method (OS-9), as the business set them (SavePaymentSettings) and only
 * what works now: card online only with the store's DNA credentials saved. A collection always keeps a way to pay
 * (pay at the shop, as in S1); a delivery with none left is not offered.
 */
final class PaymentOptions
{
    public static function cardReady(OnlineStore $store): bool
    {
        return DnaCredentials::fromStore($store) !== null;
    }

    /**
     * What the business chose (null column = the default), whether or not it works now.
     *
     * @return list<OnlinePaymentMethod>
     */
    public static function chosen(OnlineStore $store, FulfilmentMethod $method): array
    {
        $stored = $method === FulfilmentMethod::Collection ? $store->collection_payments : $store->delivery_payments;

        if (! is_array($stored)) {
            return [OnlinePaymentMethod::defaultFor($method)];
        }

        $allowed = OnlinePaymentMethod::allowedFor($method);

        return array_values(array_filter($allowed, fn (OnlinePaymentMethod $p) => in_array($p->value, $stored, true)));
    }

    /**
     * What a customer can pick for this method now.
     *
     * @return list<OnlinePaymentMethod>
     */
    public static function for(OnlineStore $store, FulfilmentMethod $method): array
    {
        $card = self::cardReady($store);
        $options = array_values(array_filter(self::chosen($store, $method), fn (OnlinePaymentMethod $p) => $card || $p !== OnlinePaymentMethod::CardOnline));

        return $options === [] && $method === FulfilmentMethod::Collection ? [OnlinePaymentMethod::PayAtShop] : $options;
    }

    public static function offers(OnlineStore $store, FulfilmentMethod $method, OnlinePaymentMethod $payment): bool
    {
        return in_array($payment, self::for($store, $method), true);
    }
}
