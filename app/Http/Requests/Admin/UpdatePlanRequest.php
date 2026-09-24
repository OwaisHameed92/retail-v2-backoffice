<?php

namespace App\Http\Requests\Admin;

use App\Domain\Plans\Models\Plan;

class UpdatePlanRequest extends PlanRequest
{
    protected function ignoredPlan(): ?Plan
    {
        $plan = $this->route('plan');

        return $plan instanceof Plan ? $plan : null;
    }
}
