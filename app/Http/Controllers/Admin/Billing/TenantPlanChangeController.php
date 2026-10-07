<?php

namespace App\Http\Controllers\Admin\Billing;

use App\Domain\Billing\Actions\ChangeBusinessPlan;
use App\Domain\Billing\Data\PlanChangePreview;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\PlanChangePlanner;
use App\Domain\Tenancy\Models\Company;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Billing\ChangePlanRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Admin → business → Billing → "Change plan" (owner 2026-10-07): a preview of what the change does (JSON, writes
 * nothing), then the confirmed change. `billing.manage` (route middleware and the request).
 */
class TenantPlanChangeController extends Controller
{
    public function preview(ChangePlanRequest $request, Company $company, PlanChangePlanner $planner): JsonResponse
    {
        return response()->json(PlanChangePreview::toArray($planner->plan($company, $request->plan(), $request->setupFee())));
    }

    public function store(ChangePlanRequest $request, Company $company, ChangeBusinessPlan $change): RedirectResponse
    {
        $plan = $request->plan();
        $result = $change->handle($company, $plan, $request->setupFee());
        $parts = ["{$company->name} is now on {$plan->name}."];

        foreach (['setupInvoice' => 'Setup fee', 'firstInvoice' => 'First period'] as $key => $label) {
            if ($result[$key] !== null) {
                $parts[] = "{$label}: {$result[$key]->number} for ".BillingFormat::money($result[$key]->total).'.';
            }
        }

        if ($result['warning'] !== null) {
            $parts[] = $result['warning'];
        }

        return back()->with('success', implode(' ', $parts));
    }
}
