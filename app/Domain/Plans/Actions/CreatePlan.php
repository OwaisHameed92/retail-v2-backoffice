<?php

namespace App\Domain\Plans\Actions;

use App\Domain\Plans\Data\PlanData;
use App\Domain\Plans\Data\PlanInput;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreatePlan
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * Create a plan and record `plan.created` in the audit log.
     *
     * @throws ValidationException when the code is already used (archived plans included)
     */
    public function handle(PlanInput $input): Plan
    {
        $attributes = $input->toAttributes();

        return DB::transaction(function () use ($attributes) {
            if (Plan::withTrashed()->where('code', $attributes['code'])->exists()) {
                throw ValidationException::withMessages(['code' => 'Another plan already uses this code.']);
            }

            $plan = Plan::query()->create($attributes);

            $this->audit->handle('plan.created', $plan, null, PlanData::audit($plan));

            return $plan;
        });
    }
}
