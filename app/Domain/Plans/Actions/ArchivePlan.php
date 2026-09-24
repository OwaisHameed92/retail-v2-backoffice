<?php

namespace App\Domain\Plans\Actions;

use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ArchivePlan
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * Archive (soft delete) a plan and record `plan.archived`. Archiving an archived plan does nothing.
     *
     * @throws ValidationException when licences still use the plan (see Plan::isInUse())
     */
    public function handle(Plan $plan): Plan
    {
        if ($plan->trashed()) {
            return $plan;
        }

        if ($plan->isInUse()) {
            throw ValidationException::withMessages([
                'plan' => "{$plan->name} is used by licences, so it cannot be archived. Make it inactive to stop new licences using it.",
            ]);
        }

        return DB::transaction(function () use ($plan) {
            $plan->delete();

            $this->audit->handle('plan.archived', $plan, ['archived' => false], ['archived' => true]);

            return $plan;
        });
    }
}
