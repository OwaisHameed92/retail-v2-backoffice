<?php

namespace App\Domain\Licensing\Data;

use App\Domain\Licensing\Actions\IssueLicence;
use App\Domain\Licensing\Actions\UpdateBranchLimits;
use App\Domain\Licensing\Api\Support\LicenceToken;
use App\Domain\Licensing\Enums\LicenceLengthUnit;
use App\Domain\Licensing\Signing\Sspos\TokenKind;
use App\Domain\Licensing\Support\PlanFeatures;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Enums\BusinessType;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Support\TenantLimits;

/**
 * The licence form for the admin pages (module 1.11): a branch's settings with "in use / allowed", the company's
 * branch limits, and the options (features with the till's names, kinds, length units, business types, plan
 * defaults). camelCase keys, ISO dates.
 */
final class LicenceFormData
{
    /**
     * @return array<string, mixed>
     */
    public static function branch(Branch $branch, ?Plan $plan): array
    {
        $settings = BranchLicenceSettings::of($branch);
        $planFeatures = PlanFeatures::forBranch($plan);

        return [
            'maxRegisters' => $settings->maxRegisters,
            'kind' => $settings->kind->value,
            'length' => $settings->length,
            'lengthUnit' => $settings->lengthUnit?->value,
            'lengthLabel' => $settings->lengthLabel(),
            'validFrom' => $settings->validFrom?->toIso8601String(),
            'features' => $settings->featureValues(),
            // Fix 2026-10-07: the plan's features (what null means) and whether the branch has its own that differ.
            'planFeatures' => array_map(fn (Feature $feature) => $feature->value, $planFeatures),
            'featuresCustom' => PlanFeatures::isCustom($branch, $plan),
            'tillsInUse' => TenantLimits::activeTills($branch->id),
            'keysInUse' => IssueLicence::keysInUse($branch->id),
            'keysActivated' => LicenceToken::registersInUse($branch->id),
        ];
    }

    /**
     * @return array{multiBranch: bool, maxBranches: int, branchesAllowed: int, branchesInUse: int}
     */
    public static function limits(Company $company): array
    {
        return [
            'multiBranch' => $company->multi_branch,
            'maxBranches' => $company->max_branches,
            'branchesAllowed' => TenantLimits::branchesAllowed($company),
            'branchesInUse' => TenantLimits::activeBranches($company->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        return [
            'features' => self::featureOptions(),
            'kinds' => [
                ['value' => TokenKind::Trial->value, 'label' => 'Trial', 'description' => 'No payment yet. Without a length the plan’s trial starts on the first activation.'],
                ['value' => TokenKind::Full->value, 'label' => 'Full', 'description' => 'Paid for the length you choose. Billing renewals extend it.'],
            ],
            'units' => LicenceLengthUnit::options(),
            'businessTypes' => BusinessType::options(),
            'maxRegisters' => BranchLicenceSettings::MAX_REGISTERS,
            'maxBranches' => UpdateBranchLimits::MAX_BRANCHES,
        ];
    }

    /**
     * The till's features (module 2.1: our values are the till's names, so `tillName` is the value). Multi-branch
     * is left out: it is the company's setting.
     *
     * @return list<array{value: string, label: string, description: string, tillName: string|null}>
     */
    public static function featureOptions(): array
    {
        $options = [];

        foreach (Feature::cases() as $feature) {
            if ($feature !== Feature::MultiBranch) {
                $options[] = ['value' => $feature->value, 'label' => $feature->label(), 'description' => $feature->description(), 'tillName' => $feature->value];
            }
        }

        return $options;
    }

    /**
     * Each active plan's defaults for a new customer's licence form: features, trial length, multi-branch.
     *
     * @return array<string, array{features: list<string>, trialDays: int, multiBranch: bool}>
     */
    public static function planDefaults(): array
    {
        return Plan::query()->where('is_active', true)->orderBy('sort_order')->get()
            ->mapWithKeys(fn (Plan $plan) => [$plan->id => [
                'features' => array_values(array_map(
                    fn (Feature $feature) => $feature->value,
                    array_filter(Feature::normalise($plan->features), fn (Feature $feature) => $feature !== Feature::MultiBranch),
                )),
                'trialDays' => $plan->trial_days,
                'multiBranch' => $plan->features->contains(Feature::MultiBranch),
            ]])
            ->all();
    }
}
