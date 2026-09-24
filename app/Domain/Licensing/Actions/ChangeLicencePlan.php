<?php

namespace App\Domain\Licensing\Actions;

use App\Domain\Licensing\Data\LicenceChange;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\DefaultPlan;
use App\Domain\Licensing\Support\LicenceGuard;
use App\Domain\Licensing\Support\LicenceTerms;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Actions\RecordAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a licence to another plan: the plan's features are copied onto the licence and its grace days follow
 * the new plan (trial or paid grace). Dates are unchanged. The till gets the new features at its next check-in.
 */
class ChangeLicencePlan
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(Licence $licence, Plan $plan): LicenceChange
    {
        if (! DefaultPlan::isOffered($plan)) {
            throw ValidationException::withMessages(['plan_id' => "{$plan->name} is not offered any more. Choose an active plan."]);
        }

        return DB::transaction(function () use ($licence, $plan) {
            $licence = LicenceGuard::lock($licence);
            LicenceGuard::ensureNotRevoked($licence, 'change its plan');

            if ($licence->plan_id === $plan->id) {
                return new LicenceChange($licence, $licence->status, $licence->status, changed: false);
            }

            $old = $licence->plan;
            $before = [
                'plan' => $old?->code,
                'features' => $licence->features->map(fn (Feature $f) => $f->value)->values()->all(),
                'grace_days' => $licence->grace_days,
            ];

            $licence->plan_id = $plan->id;
            $licence->features = $plan->features;
            $licence->grace_days = LicenceTerms::graceDaysFor($licence, $plan);
            $licence->status = LicenceTerms::storedStatusAfterDateChange($licence, CarbonImmutable::now());
            $licence->save();
            $licence->setRelation('plan', $plan);

            $this->audit->handle('licence.plan_changed', $licence, $before, [
                'plan' => $plan->code,
                'features' => $plan->featureValues(),
                'grace_days' => $licence->grace_days,
            ], ['from_plan_name' => $old?->name, 'to_plan_name' => $plan->name]);

            return new LicenceChange($licence, $licence->status, $licence->status);
        });
    }
}
