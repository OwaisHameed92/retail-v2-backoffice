<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Enums\FulfilmentMethod;
use App\Domain\OnlineStore\Enums\OnlinePaymentMethod;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Support\PaymentOptions;
use App\Domain\OnlineStore\Support\StoreScope;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Validation\ValidationException;

/**
 * The ways to pay the store offers (OS-9, portal → Delivery & payments, `store.manage`): for a collection, pay at the
 * shop and/or card online; for a delivery, card online and/or pay on delivery. Each method needs at least one. Card
 * online can be chosen only once the store's DNA Payments credentials are saved (SaveDnaCredentials). Audited.
 */
final class SavePaymentSettings
{
    public function __construct(private readonly RecordAudit $audit, private readonly CurrentCompany $tenancy) {}

    /**
     * @param  array<string, mixed>  $input  `collection` and `delivery`: lists of payment method values
     *
     * @throws ValidationException
     */
    public function handle(Company $company, array $input): OnlineStore
    {
        return $this->tenancy->runAs($company, function () use ($company, $input): OnlineStore {
            $store = StoreScope::storeOf($company);
            $card = PaymentOptions::cardReady($store);
            $chosen = [];

            foreach (FulfilmentMethod::cases() as $method) {
                $values = is_array($input[$method->value] ?? null) ? array_map('strval', $input[$method->value]) : [];
                $allowed = OnlinePaymentMethod::allowedFor($method);
                $picked = array_values(array_filter($allowed, fn (OnlinePaymentMethod $p) => in_array($p->value, $values, true)));

                if (count($picked) !== count(array_unique($values)) || $picked === []) {
                    throw ValidationException::withMessages([$method->value => 'Choose at least one way to pay for '.($method === FulfilmentMethod::Collection ? 'a collection.' : 'a delivery.')]);
                }

                if (! $card && in_array(OnlinePaymentMethod::CardOnline, $picked, true)) {
                    throw ValidationException::withMessages([$method->value => 'Save your DNA Payments details first to take card payments online.']);
                }

                $chosen[$method->value] = array_map(fn (OnlinePaymentMethod $p) => $p->value, $picked);
            }

            $before = ['collection' => PaymentOptions::chosen($store, FulfilmentMethod::Collection), 'delivery' => PaymentOptions::chosen($store, FulfilmentMethod::Delivery)];
            $store->forceFill(['collection_payments' => $chosen['collection'], 'delivery_payments' => $chosen['delivery']])->save();

            $this->audit->handle('online_store.payments_updated', $store,
                array_map(fn (array $list) => array_map(fn (OnlinePaymentMethod $p) => $p->value, $list), $before), $chosen, companyId: $company->id);

            return $store->refresh();
        });
    }
}
