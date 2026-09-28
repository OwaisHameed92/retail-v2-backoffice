<?php

namespace App\Domain\Plans\Data;

use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Support\Money;

/**
 * Shapes a Plan for Inertia pages and for the audit log.
 */
final class PlanData
{
    /**
     * Row for the plans DataTable.
     *
     * @return array{id: string, name: string, code: string, pricePerTillMonthly: string, pricePerTillYearly: string, currency: string, trialDays: int, featureCount: int, status: string, statusLabel: string, sortOrder: int}
     */
    public static function row(Plan $plan): array
    {
        $status = $plan->status();

        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'code' => $plan->code,
            'pricePerTillMonthly' => $plan->price_per_till_monthly,
            'pricePerTillYearly' => $plan->price_per_till_yearly,
            'currency' => $plan->currency,
            'trialDays' => $plan->trial_days,
            'featureCount' => $plan->features->count(),
            'status' => $status->value,
            'statusLabel' => $status->label(),
            'sortOrder' => $plan->sort_order,
        ];
    }

    /**
     * Full plan for the detail and edit pages.
     *
     * @return array<string, mixed>
     */
    public static function fromModel(Plan $plan): array
    {
        $status = $plan->status();

        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'code' => $plan->code,
            'description' => $plan->description,
            'pricePerTillMonthly' => $plan->price_per_till_monthly,
            'pricePerTillYearly' => $plan->price_per_till_yearly,
            'setupFee' => $plan->setup_fee,
            'currency' => $plan->currency,
            'trialDays' => $plan->trial_days,
            'trialGraceDays' => $plan->trial_grace_days,
            'graceDays' => $plan->grace_days,
            'yearlySaving' => self::yearlySaving($plan),
            'yearlySavingPercent' => self::yearlySavingPercent($plan),
            'features' => $plan->featureValues(),
            'isActive' => $plan->is_active,
            'isPublic' => $plan->is_public,
            'sortOrder' => $plan->sort_order,
            'status' => $status->value,
            'statusLabel' => $status->label(),
            'isInUse' => $plan->isInUse(),
            'createdAt' => $plan->created_at?->toIso8601String(),
            'updatedAt' => $plan->updated_at?->toIso8601String(),
            'archivedAt' => $plan->deleted_at?->toIso8601String(),
        ];
    }

    /**
     * Values written to the audit log (before/after). Money stays as 2 dp strings.
     *
     * @return array<string, mixed>
     */
    public static function audit(Plan $plan): array
    {
        return [
            'name' => $plan->name,
            'code' => $plan->code,
            'description' => $plan->description,
            'price_per_till_monthly' => $plan->price_per_till_monthly,
            'price_per_till_yearly' => $plan->price_per_till_yearly,
            'setup_fee' => $plan->setup_fee,
            'currency' => $plan->currency,
            'trial_days' => $plan->trial_days,
            'trial_grace_days' => $plan->trial_grace_days,
            'grace_days' => $plan->grace_days,
            'features' => $plan->featureValues(),
            'is_active' => $plan->is_active,
            'is_public' => $plan->is_public,
            'sort_order' => $plan->sort_order,
        ];
    }

    /**
     * Only the keys whose values differ, as [before, after].
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function diff(array $before, array $after): array
    {
        $changedBefore = [];
        $changedAfter = [];

        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changedBefore[$key] = $before[$key] ?? null;
                $changedAfter[$key] = $value;
            }
        }

        return [$changedBefore, $changedAfter];
    }

    /** What a till saves per year on yearly billing compared with 12 monthly payments ("60.00"). */
    public static function yearlySaving(Plan $plan): string
    {
        return Money::sub(Money::mul($plan->price_per_till_monthly, 12), $plan->price_per_till_yearly);
    }

    /** The yearly saving as a whole percentage of 12 monthly payments, or null when monthly is free. */
    public static function yearlySavingPercent(Plan $plan): ?int
    {
        $twelveMonths = Money::mul($plan->price_per_till_monthly, 12);

        if (Money::isZero($twelveMonths)) {
            return null;
        }

        $ratio = bcdiv(bcmul(self::yearlySaving($plan), '100', 4), $twelveMonths, 4);

        return (int) Money::round($ratio, 0);
    }
}
