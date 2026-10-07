<?php

namespace App\Domain\Licensing\Support;

use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Branch;

/**
 * A branch's features against its plan (fix 2026-10-07, DECISIONS "Branch features follow the plan").
 *
 * `branches.licence_features` null = the branch follows its plan, so a later plan edit reaches its new keys and any
 * re-application of its settings. A list is stored only when it really differs from the plan (order and
 * multi-branch, which is the company's, ignored), compared with the plan as it is at save time.
 */
final class PlanFeatures
{
    /**
     * A plan's features as a branch carries them: enum order, without multi-branch.
     *
     * @return list<Feature>
     */
    public static function forBranch(?Plan $plan): array
    {
        return self::withoutMultiBranch($plan->features ?? []);
    }

    /**
     * True when the features equal the plan's. False without a plan (nothing to follow).
     *
     * @param  iterable<Feature|string>  $features
     */
    public static function same(iterable $features, ?Plan $plan): bool
    {
        return $plan !== null && self::withoutMultiBranch($features) === self::forBranch($plan);
    }

    /**
     * What to store for these features: null (follow the plan) when they equal the plan's, else the list.
     *
     * @param  list<Feature>|null  $features
     * @return list<Feature>|null
     */
    public static function toStore(?array $features, ?Plan $plan): ?array
    {
        return $features !== null && self::same($features, $plan) ? null : $features;
    }

    /** The branch has its own features that differ from the plan's ("Custom features"). */
    public static function isCustom(Branch $branch, ?Plan $plan): bool
    {
        return $branch->licence_features !== null && ! self::same($branch->licence_features, $plan);
    }

    /**
     * @param  iterable<Feature|string>  $features
     * @return list<Feature>
     */
    private static function withoutMultiBranch(iterable $features): array
    {
        return array_values(array_filter(Feature::normalise($features), fn (Feature $feature) => $feature !== Feature::MultiBranch));
    }
}
