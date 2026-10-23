<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\Tenancy\Models\Company;

/**
 * The public store at an address (`/{slug}`), for requests with no signed-in user. Found only when this instance
 * runs online stores (OnlineStoreSwitch: `ONLINE_STORE` and the country flag), the store is live, its business
 * exists and may trade (not suspended or cancelled), and (OS-6) the business's add-on is on, with cloud sync and a
 * monthly plan. Otherwise null: the public page answers 404.
 *
 * The store is the only row read across businesses (by its globally unique slug, with the `company` relation set);
 * everything after this runs scoped to that store's business (CurrentCompany::runAs), never on an ambient scope.
 */
final class FindStoreBySlug
{
    public function handle(string $slug): ?OnlineStore
    {
        $slug = strtolower(trim($slug));

        if (! OnlineStoreSwitch::enabled() || preg_match('/^[a-z0-9-]{3,40}$/', $slug) !== 1) {
            return null;
        }

        $store = OnlineStore::withoutCompanyScope()->where('slug', $slug)->where('is_live', true)->first();

        if ($store === null) {
            return null;
        }

        $company = Company::query()->find($store->company_id);

        // OS-6: the add-on is on, with cloud sync and a monthly plan (OnlineStoreSwitch::availableFor).
        if ($company === null || ! $company->status->allowsPortalAccess() || ! OnlineStoreSwitch::availableFor($company, $store)) {
            return null;
        }

        return $store->setRelation('company', $company);
    }

    /**
     * Whether a store found earlier may still take an order now: the switch is on, it is still live and taking
     * orders, and its business may still trade. Reads the store again.
     */
    public function takingOrders(OnlineStore $store): bool
    {
        $current = $this->handle($store->slug);

        return $current !== null && $current->id === $store->id && $current->taking_orders;
    }
}
