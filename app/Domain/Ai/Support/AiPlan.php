<?php

namespace App\Domain\Ai\Support;

use App\Domain\Licensing\Support\DefaultPlan;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Company;

/**
 * The plan whose features and AI budget apply to a company: its own plan (`companies.plan_id`, even if the plan
 * is no longer offered to new customers), else the portal default plan (module 1.3's DefaultPlan, when present).
 */
final class AiPlan
{
    public static function for(Company $company): ?Plan
    {
        if ($company->plan_id !== null) {
            $plan = Plan::withTrashed()->find($company->plan_id);

            if ($plan !== null) {
                return $plan;
            }
        }

        return class_exists(DefaultPlan::class) ? DefaultPlan::portal() : null;
    }
}
