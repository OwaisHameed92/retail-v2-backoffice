<?php

namespace App\Http\Controllers\Admin\Leads;

use App\Domain\Leads\Actions\ApproveTrial;
use App\Domain\Leads\Models\Lead;
use App\Domain\Mail\Enums\EmailCategory;
use App\Domain\Mail\Support\EmailControl;
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

        // P11: with "Welcome and licence keys" not sent automatically, the email waits on the business's Emails tab.
        if (! EmailControl::sendsAutomatically(EmailCategory::Welcome)) {
            return redirect()->route('admin.tenants.show', ['company' => $company, 'tab' => 'emails'])->with(
                'success',
                "Trial approved. {$company->name} is set up. Its welcome email with the licence keys is held: check the business, then send it here.",
            );
        }

        return redirect()->route('admin.tenants.show', $company)->with(
            'success',
            "Trial approved. {$company->name} is set up and {$lead->contact_name} gets the welcome email with the licence keys.",
        );
    }
}
