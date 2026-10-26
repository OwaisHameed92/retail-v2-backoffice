<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\Licence;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Validation\ValidationException;

/**
 * OS-13 (owner 2026-10-26): the "Online store only" offer, for a business with no till yet. Its plan
 * (`plans.online_store_only`, Plan::isOnlineStoreOnly) is a recurring plan with no tills whose monthly fee is the online
 * store fee, once per business. Such a business:
 *
 * - has no registers and no licences (no till licence is ever issued on that plan: `assertTillPlan`);
 * - sells the products made in the portal (hub products every till pulls later) at the business price, with the
 *   shop's own "In stock / Out of stock" and stock count (ManualStock), so its store needs no cloud sync;
 * - pays the single "Online store" line on its period invoice (InvoiceLineBuilder), by the same Direct Debit path.
 *
 * `sellsWithoutTills` is the one rule the store, the catalogue and billing read: no live till licence, and the business
 * is on such a plan or started on one (`online_stores.sells_without_tills`, kept after Change plan to a plan with
 * tills, so the store keeps trading until the first till licence is issued; then the tills' stock and prices take
 * over). Read across businesses by id, never through the ambient scope. Nothing here goes to the tills.
 */
final class OnlineStoreOnly
{
    /** The business's own plan when it is an online store only plan (active or not, never archived), else null. */
    public static function ownPlan(Company $company): ?Plan
    {
        if ($company->plan_id === null) {
            return null;
        }

        $plan = Plan::query()->whereKey($company->plan_id)->where('online_store_only', true)->first();

        return $plan?->isOnlineStoreOnly() ? $plan : null;
    }

    /** The business has a live (current, not revoked) till licence. */
    public static function hasLiveTills(Company|string $company): bool
    {
        return Licence::withoutCompanyScope()->where('company_id', $company instanceof Company ? $company->id : $company)->live()
            ->where('status', '!=', LicenceStatus::Revoked->value)->exists();
    }

    /**
     * The business sells online without tills now: no live till licence, and it is on an online store only plan or
     * started on one. `$store` saves a read when the caller already has the row.
     */
    public static function sellsWithoutTills(Company $company, ?OnlineStore $store = null): bool
    {
        if (self::hasLiveTills($company)) {
            return false;
        }

        $store ??= OnlineStoreAddOn::store($company);

        return ($store !== null && $store->sells_without_tills) || self::ownPlan($company) !== null;
    }

    /** By company id (the public store's catalogue has the shop's `company_id` only). */
    public static function sellsWithoutTillsById(string $companyId): bool
    {
        $company = Company::query()->find($companyId);

        return $company !== null && self::sellsWithoutTills($company);
    }

    /** Marks the business's store as started without tills (kept for good; it only counts while there is no live till). */
    public static function remember(Company $company): void
    {
        OnlineStore::withoutCompanyScope()->where('company_id', $company->id)->where('sells_without_tills', false)
            ->update(['sells_without_tills' => true]);
    }

    /**
     * No till licence on an online store only plan, and no till added to a business on one.
     *
     * @throws ValidationException
     */
    public static function assertTillPlan(?Plan $plan, string $key = 'plan_id'): void
    {
        if ($plan !== null && $plan->isOnlineStoreOnly()) {
            throw ValidationException::withMessages([$key => "{$plan->name} is an online store only plan: it has no tills. Move the business to a plan with tills first (Billing → Change plan)."]);
        }
    }

    /**
     * A till may be added to this business: not while it is on an online store only plan.
     *
     * @throws ValidationException
     */
    public static function ensureCanAddTill(Company $company, string $key = 'code'): void
    {
        if (! self::hasLiveTills($company)) {
            self::assertTillPlan(self::ownPlan($company), $key);
        }
    }
}
