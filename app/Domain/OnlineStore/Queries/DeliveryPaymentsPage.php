<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Enums\FulfilmentMethod;
use App\Domain\OnlineStore\Enums\OnlinePaymentMethod;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Payments\DnaCredentials;
use App\Domain\OnlineStore\Support\PaymentOptions;
use App\Domain\Tenancy\Models\Company;

/**
 * The portal's "Delivery & payments" tab (OS-9, `app/online-store/delivery`): local delivery per collection shop
 * (SaveDeliverySettings), the ways to pay per method (SavePaymentSettings) and the store's DNA Payments account
 * (SaveDnaCredentials). The DNA credentials never leave the server: only whether they are saved and their last 4
 * characters. A one-shop user sees their own shop and cannot change payments.
 */
final class DeliveryPaymentsPage
{
    public const SLOT_OPTIONS = [15, 30, 45, 60, 90, 120, 180, 240];

    public const DEFAULTS = ['slotMinutes' => 60, 'ordersPerSlot' => 4, 'leadMinutes' => 120];

    /**
     * @return array<string, mixed>
     */
    public static function for(Company $company, ?string $onlyBranchId): array
    {
        $store = OnlineStore::query()->where('company_id', $company->id)->first();
        $shops = $store === null ? collect() : OnlineStoreShop::query()->with('branch')->where('company_id', $company->id)->where('online_store_id', $store->id)
            ->when($onlyBranchId !== null, fn ($q) => $q->where('branch_id', $onlyBranchId))->get()
            ->filter(fn (OnlineStoreShop $s) => $s->branch->is_active)
            ->sortBy(fn (OnlineStoreShop $s) => $s->branch->name)->values();

        return [
            'hasStore' => $store !== null,
            'canEditPayments' => $onlyBranchId === null,
            'shops' => $shops->map(fn (OnlineStoreShop $s) => self::shop($s))->all(),
            'slotOptions' => self::SLOT_OPTIONS,
            'payments' => $store === null ? null : self::payments($store),
            'dna' => $store === null ? null : [
                'configured' => PaymentOptions::cardReady($store),
                'clientId' => DnaCredentials::last4($store->dna_client_id),
                'terminalId' => DnaCredentials::last4($store->dna_terminal_id),
                'secret' => DnaCredentials::last4($store->dna_client_secret),
                'testMode' => (bool) $store->dna_test_mode,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function shop(OnlineStoreShop $shop): array
    {
        $set = $shop->delivery_hours !== null;

        return [
            'id' => $shop->branch_id,
            'name' => $shop->branch->name,
            'code' => $shop->branch->code,
            'town' => $shop->branch->town,
            'postcode' => $shop->branch->postcode,
            'collects' => $shop->enabled,
            'enabled' => $shop->delivery_enabled,
            'areas' => $shop->delivery_areas ?? [],
            'fee' => $shop->delivery_fee,
            'freeOver' => $shop->delivery_free_over,
            'minimum' => $shop->delivery_minimum,
            'days' => array_map(function (string $day) use ($shop, $set): array {
                $hours = $set ? $shop->deliveryHoursOn($day) : $shop->hoursOn($day);

                return ['day' => $day, 'open' => $hours['open'] ?? null, 'close' => $hours['close'] ?? null];
            }, OnlineStoreShop::WEEKDAYS),
            'hoursSaved' => $set,
            'slotMinutes' => $set ? $shop->delivery_slot_minutes : self::DEFAULTS['slotMinutes'],
            'ordersPerSlot' => $set ? $shop->delivery_orders_per_slot : self::DEFAULTS['ordersPerSlot'],
            'leadMinutes' => $set ? $shop->delivery_lead_minutes : self::DEFAULTS['leadMinutes'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function payments(OnlineStore $store): array
    {
        $out = ['cardReady' => PaymentOptions::cardReady($store)];

        foreach (FulfilmentMethod::cases() as $method) {
            $out[$method->value] = [
                'chosen' => array_map(fn (OnlinePaymentMethod $p) => $p->value, PaymentOptions::chosen($store, $method)),
                'options' => array_map(fn (OnlinePaymentMethod $p) => ['value' => $p->value, 'label' => $p->label()], OnlinePaymentMethod::allowedFor($method)),
            ];
        }

        return $out;
    }
}
