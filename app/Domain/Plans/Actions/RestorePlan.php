<?php

namespace App\Domain\Plans\Actions;

use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Support\Facades\DB;

class RestorePlan
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * Bring an archived plan back with its previous settings and record `plan.restored`.
     * Restoring a plan that is not archived does nothing.
     */
    public function handle(Plan $plan): Plan
    {
        if (! $plan->trashed()) {
            return $plan;
        }

        return DB::transaction(function () use ($plan) {
            $plan->restore();

            $this->audit->handle('plan.restored', $plan, ['archived' => true], ['archived' => false]);

            return $plan;
        });
    }
}
