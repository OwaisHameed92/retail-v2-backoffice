<?php

namespace App\Domain\Handheld\Support;

use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Licensing\Actions\RenewCompanyLicences;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\DefaultPlan;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;

/**
 * May this handheld user use the app right now? Checked at login and on every call: the business is open (not
 * suspended, cancelled or removed: 403 BUSINESS_SUSPENDED / BUSINESS_CANCELLED), its plan includes the handheld app
 * (403 PLAN_NOT_INCLUDED, `allowed()`), the account is active (403 ACCOUNT_INACTIVE) and its shop is open (403
 * SHOP_CLOSED). Runs before a current company is set, so it reads the rows across companies by the user's own ids
 * (auth code, like the sync keys). Tokens are never revoked by the plan rule: they work again once the plan includes it.
 */
final class HandheldAccess
{
    /**
     * @return array{0: Company, 1: Branch}
     *
     * @throws ApiException
     */
    public static function assertAllowed(HandheldUser $user): array
    {
        $company = Company::withTrashed()->find($user->company_id);

        if ($company === null || $company->trashed() || $company->status === CompanyStatus::Cancelled) {
            throw PdaErrors::cancelled();
        }

        if ($company->status === CompanyStatus::Suspended) {
            throw PdaErrors::suspended();
        }

        if (! self::allowed($company)) {
            throw PdaErrors::planNotIncluded();
        }

        if (! $user->is_active) {
            throw PdaErrors::inactive();
        }

        $shop = Branch::withoutCompanyScope()->withTrashed()->where('company_id', $company->id)->find($user->branch_id);

        if (! $shop instanceof Branch || $shop->trashed() || ! $shop->is_active) {
            throw PdaErrors::shopClosed();
        }

        return [$company, $shop];
    }

    /**
     * Owner 2026-10-21: the handheld app comes with the business's plan (`plans.includes_handheld`, on for monthly and
     * yearly plans). Used by the PDA API, the portal's handheld users screen and the admin business page.
     */
    public static function allowed(Company $company): bool
    {
        return self::plan($company)?->includes_handheld === true;
    }

    /**
     * The business's plan as billing reads it (CompanyPricing, DefaultPlan::forNewTill): the plan of its first live
     * till that has one, else the plan new tills get (`DefaultPlan::for`). The same rule in one or two small queries
     * (no relations loaded), as it runs on every PDA call.
     */
    public static function plan(Company $company): ?Plan
    {
        $licensed = RenewCompanyLicences::renewable($company)->without(['branch', 'register', 'company'])->whereHas('plan')->first();

        return $licensed instanceof Licence ? $licensed->plan : DefaultPlan::for($company);
    }
}
