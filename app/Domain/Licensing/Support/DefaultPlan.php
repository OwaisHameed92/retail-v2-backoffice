<?php

namespace App\Domain\Licensing\Support;

use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Company;

/**
 * Which plan a new till of a company is licensed on:
 *
 * 1. the company's own plan (`companies.plan_id`), when it is active and not archived;
 * 2. else the portal default (`config('licence.default_plan')`, a plan code), when active;
 * 3. else the first active plan by sort order;
 * 4. else none (no plan can be offered yet: tills stay unlicensed until one exists).
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
