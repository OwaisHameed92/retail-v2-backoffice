<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Support\StoreScope;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Validation\ValidationException;

/**
 * The store builder's "Taking orders" switch. Off: the store stays on line (customers can still browse) but
 * PlaceCollectOrder refuses every order. Nothing is written when nothing changes. Audited.
 */
final class SetTakingOrders
{
    public function __construct(private readonly RecordAudit $audit, private readonly CurrentCompany $tenancy) {}

    /**
     * @throws ValidationException when the business has no store yet
     */
    public function handle(Company $company, bool $takingOrders): OnlineStore
    {
        return $this->tenancy->runAs($company, function () use ($company, $takingOrders): OnlineStore {
            $store = StoreScope::storeOf($company);

            if ($store->taking_orders === $takingOrders) {
                return $store;
            }

            $store->forceFill(['taking_orders' => $takingOrders])->save();
            $this->audit->handle($takingOrders ? 'online_store.orders_opened' : 'online_store.orders_paused', $store,
                ['taking_orders' => ! $takingOrders], ['taking_orders' => $takingOrders], companyId: $company->id);

            return $store;
        });
    }
}
