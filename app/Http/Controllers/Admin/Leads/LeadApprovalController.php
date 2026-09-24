<?php

namespace App\Http\Controllers\Admin\Leads;

use App\Domain\Leads\Actions\ApproveTrial;
use App\Domain\Leads\Models\Lead;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Leads\ApproveTrialRequest;
use Illuminate\Http\RedirectResponse;

/**
 * "Approve 7-day trial": creates the tenant (company, branches, tills, licences, owner) and opens its page.
 * The licence keys go to the owner by email only.
 */
class LeadApprovalController extends Controller
{
    public function store(ApproveTrialRequest $request, Lead $lead, ApproveTrial $approveTrial): RedirectResponse
    {
        $company = $approveTrial->handle($lead, $request->setup());

        return redirect()->route('admin.tenants.show', $company)->with(
            'success',
            "Trial approved. {$company->name} is set up and {$lead->contact_name} gets the welcome email with the licence keys.",
        );
    }
}
