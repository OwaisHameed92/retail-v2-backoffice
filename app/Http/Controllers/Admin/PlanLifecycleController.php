<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Plans\Actions\ArchivePlan;
use App\Domain\Plans\Actions\DuplicatePlan;
use App\Domain\Plans\Actions\RestorePlan;
use App\Domain\Plans\Models\Plan;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Archive, restore and duplicate a plan (ability `billing.manage`, enforced by route middleware).
 */
class PlanLifecycleController extends Controller
{
    use PlanToasts;

    public function archive(Request $request, Plan $plan, ArchivePlan $archivePlan): RedirectResponse
    {
        $archivePlan->handle($plan);

        $this->toast($request, "{$plan->name} plan archived.");

        return redirect()->route('admin.plans.index');
    }

    public function restore(Request $request, Plan $plan, RestorePlan $restorePlan): RedirectResponse
    {
        $restorePlan->handle($plan);

        $this->toast($request, "{$plan->name} plan restored.");

        return redirect()->route('admin.plans.show', $plan);
    }

    public function duplicate(Request $request, Plan $plan, DuplicatePlan $duplicatePlan): RedirectResponse
    {
        $copy = $duplicatePlan->handle($plan);

        $this->toast($request, 'Copy created. It stays inactive until you make it available.');

        return redirect()->route('admin.plans.edit', $copy);
    }
}
