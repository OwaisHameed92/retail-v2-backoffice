<?php

namespace App\Domain\OnlineStore\Actions;

use App\Domain\OnlineStore\Data\OnlineStoreAddOnInput;
use App\Domain\OnlineStore\Enums\StoreTemplate;
use App\Domain\OnlineStore\Support\OnlineStoreAddOn;
use App\Domain\OnlineStore\Support\OnlineStoreOnly;
use App\Domain\OnlineStore\Support\PlanOnlineStore;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Validation\ValidationException;

/**
 * OS-7 (owner 2026-10-24, option B): the online store add-on follows the business's plan when a plan is given to it
 * (CreateTenant: the admin wizard, partner and trial approvals; ChangeBusinessPlan: Change plan).
 *
 * - The plan includes the online store and the add-on is off: switched on through SetOnlineStoreAddOn (the store row is
 *   made when there is none: address from the business name, not live, not taking orders) with the template chosen,
 *   Fresh Market when none is given and the business has no store yet. The business's own price is kept.
 * - The plan includes it and the add-on is on: nothing changes (its template and price stay).
 * - The plan does not include it and the add-on is on (by a plan or by hand on the Billing tab): switched off, so the
 *   store closes and the "Online store" line stops from the next invoice (the store's settings are kept).
 *
 * OS-13: on an online store only plan the store is also marked as selling without tills (OnlineStoreOnly::remember).
 *
 * Nothing where the country has no online stores (PK). The caller updates the Direct Debit amount afterwards. Never
 * touches licences or anything the tills receive. Returns 'on', 'off' or null (nothing changed).
 */
class FollowPlanOnlineStore
{
    public function __construct(private readonly SetOnlineStoreAddOn $setAddOn) {}

    /**
     * @throws ValidationException under `plan_id` (for example online stores switched off on this server)
     */
    public function handle(Company $company, Plan $plan, ?StoreTemplate $template = null): ?string
    {
        if (! PlanOnlineStore::offered()) {
            return null;
        }

        $store = OnlineStoreAddOn::store($company);
        $on = $store !== null && $store->addon_on;

        if (PlanOnlineStore::includes($plan) === $on) {
            self::rememberOnlineOnly($company, $plan);

            return null;
        }

        $input = $on
            ? new OnlineStoreAddOnInput(false, $store->monthly_price, planCode: $plan->code)
            : new OnlineStoreAddOnInput(true, $store?->monthly_price, $template ?? ($store === null ? StoreTemplate::FreshMarket : null), $plan->code);

        try {
            $this->setAddOn->handle($company, $input);
        } catch (ValidationException $exception) {
            $why = (string) collect($exception->errors())->flatten()->first();

            throw ValidationException::withMessages(['plan_id' => $on ? $why : "{$plan->name} includes the online store. {$why}"]);
        }

        self::rememberOnlineOnly($company, $plan);

        return $on ? 'off' : 'on';
    }

    /** OS-13: a business put on an online store only plan sells without tills until it has a live till. */
    private static function rememberOnlineOnly(Company $company, Plan $plan): void
    {
        if (PlanOnlineStore::includes($plan) && $plan->isOnlineStoreOnly()) {
            OnlineStoreOnly::remember($company);
        }
    }
}
