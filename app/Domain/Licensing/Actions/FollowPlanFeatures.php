<?php

namespace App\Domain\Licensing\Actions;

use App\Domain\Licensing\Support\DefaultPlan;
use App\Domain\Licensing\Support\PlanFeatures;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Existing branches whose stored feature list pins them to their plan (`licences:features-follow-plan`, fix
 * 2026-10-07). A list equal to the company's plan's features is set to null (the branch follows the plan; its keys
 * keep the same features). A list that differs is a deliberate choice: listed, and reset to the plan's only for one
 * business when asked (`$resetDiffering`). Each change goes through UseBranchPlanFeatures (audited, keys follow).
 * Idempotent: a second run finds nothing to change.
 */
class FollowPlanFeatures
{
    public const FOLLOWS = 'follows';

    public const DIFFERS = 'differs';

    public const RESET = 'reset';

    public const FAILED = 'failed';

    public function __construct(private readonly UseBranchPlanFeatures $usePlan) {}

    /**
     * @return list<array{company: string, branch: string, plan: string|null, features: list<string>, planFeatures: list<string>, outcome: string, error: string|null}>
     */
    public function handle(?Company $company = null, bool $dryRun = true, bool $resetDiffering = false): array
    {
        if ($resetDiffering && $company === null) {
            throw new InvalidArgumentException('Resetting branches whose features differ from the plan needs one business.');
        }

        $branches = Branch::withoutCompanyScope()->with('company')->whereNotNull('licence_features')
            ->when($company !== null, fn ($query) => $query->where('company_id', $company?->id))
            ->orderBy('company_id')->orderBy('code')->get();

        /** @var array<string, Plan|null> $plans */
        $plans = [];
        $rows = [];

        foreach ($branches as $branch) {
            $owner = $branch->company;

            if ($owner === null) {
                continue;
            }

            $plan = array_key_exists($owner->id, $plans) ? $plans[$owner->id] : ($plans[$owner->id] = DefaultPlan::for($owner));
            $follows = PlanFeatures::same($branch->licence_features ?? [], $plan);
            $outcome = $follows ? self::FOLLOWS : ($resetDiffering ? self::RESET : self::DIFFERS);
            $error = null;

            if (! $dryRun && $outcome !== self::DIFFERS) {
                try {
                    $this->usePlan->handle($branch);
                } catch (ValidationException $e) {
                    [$outcome, $error] = [self::FAILED, $e->getMessage()];
                }
            }

            $rows[] = [
                'company' => $owner->name,
                'branch' => trim(($branch->code ?? '').' '.$branch->name),
                'plan' => $plan?->name,
                'features' => self::values(Feature::normalise($branch->licence_features ?? [])),
                'planFeatures' => self::values(PlanFeatures::forBranch($plan)),
                'outcome' => $outcome,
                'error' => $error,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<Feature>  $features
     * @return list<string>
     */
    private static function values(array $features): array
    {
        return array_map(fn (Feature $feature) => $feature->value, $features);
    }
}
