<?php

namespace App\Domain\Plans\Actions;

use App\Domain\Plans\Data\PlanData;
use App\Domain\Plans\Data\PlanInput;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdatePlan
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * Update a plan. Records `plan.updated` with only the changed values; saving with no changes writes nothing
     * (check `$plan->wasChanged()`).
     *
     * @throws ValidationException when the plan is archived or the code is used by another plan
     */
    public function handle(Plan $plan, PlanInput $input): Plan
    {
        if ($plan->trashed()) {
            throw ValidationException::withMessages(['plan' => "Restore {$plan->name} before editing it."]);
        }

        $attributes = $input->toAttributes();

        return DB::transaction(function () use ($plan, $attributes) {
            $taken = Plan::withTrashed()->where('code', $attributes['code'])->whereKeyNot($plan->getKey())->exists();

            if ($taken) {
                throw ValidationException::withMessages(['code' => 'Another plan already uses this code.']);
            }

            $before = PlanData::audit($plan);
            $plan->fill($attributes);

            // Compare cast values, not raw ones: SQLite hands decimals back as "30", which Eloquent would
            // count as a change from "30.00".
            [$changedBefore, $changedAfter] = PlanData::diff($before, PlanData::audit($plan));

            if ($changedAfter === []) {
                $plan->syncOriginal();

                return $plan;
            }

            $plan->save();

            $this->audit->handle('plan.updated', $plan, $changedBefore, $changedAfter);

            return $plan;
        });
    }
}
