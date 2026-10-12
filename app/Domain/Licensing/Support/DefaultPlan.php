<?php

namespace App\Domain\Licensing\Support;

use App\Domain\Licensing\Actions\RenewCompanyLicences;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Company;

/**
 * Which plan a new till of a company is licensed on:
 *
 * 1. the company's own plan (`companies.plan_id`), when it is active and not archived;
 * 2. else the portal default (`config('licence.default_plan')`, a plan code), when active;
 * 3. else the first active plan by sort order;
 * 4. else none (no plan can be offered yet: tills stay unlicensed until one exists).
 *
 * A till added to a business that already has live tills follows them instead (`forNewTill`, audit BILL-1).
 */
final class DefaultPlan
{
    public static function for(Company $company): ?Plan
    {
        if ($company->plan_id !== null) {
            $own = Plan::query()->whereKey($company->plan_id)->where('is_active', true)->first();

            if ($own !== null) {
                return $own;
            }
        }

        return self::portal();
    }

    /**
     * The plan a till added to a business gets, and the plan its added-till setup fee is worked out on: the plan the
     * business's live tills are on (the business's plan as billing reads it, CompanyPricing), even when that plan is
     * no longer offered to new businesses (inactive; archived plans are never used). Without live tills: `for()`.
     * Audit BILL-1: a till must not land on another plan and start a recurring fee the business never had.
     */
    public static function forNewTill(Company $company): ?Plan
    {
        $licensed = RenewCompanyLicences::renewable($company)->get()->first(fn (Licence $licence) => $licence->plan !== null);

        return $licensed instanceof Licence ? $licensed->plan : self::for($company);
    }

    /** `forNewTill` kept this plan for the business although it is not offered any more (inactive, not archived). */
    public static function isKeptFor(Plan $plan, Company $company): bool
    {
        return ! $plan->trashed() && self::forNewTill($company)?->is($plan) === true;
    }

    /** The portal-wide default (steps 2 and 3). */
    public static function portal(): ?Plan
    {
        $code = (string) config('licence.default_plan', '');

        if ($code !== '') {
            $configured = Plan::query()->where('code', $code)->where('is_active', true)->first();

            if ($configured !== null) {
                return $configured;
            }
        }

        return Plan::query()->where('is_active', true)->ordered()->first();
    }

    /** A plan may be given to new or changed licences: active and not archived. */
    public static function isOffered(Plan $plan): bool
    {
        return $plan->is_active && ! $plan->trashed();
    }
}
