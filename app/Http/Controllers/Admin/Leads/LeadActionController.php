<?php

namespace App\Http\Controllers\Admin\Leads;

use App\Domain\Leads\Actions\AddLeadNote;
use App\Domain\Leads\Actions\ArchiveLead;
use App\Domain\Leads\Actions\AssignLead;
use App\Domain\Leads\Actions\MarkContacted;
use App\Domain\Leads\Actions\RejectLead;
use App\Domain\Leads\Actions\ReopenLead;
use App\Domain\Leads\Actions\RestoreLead;
use App\Domain\Leads\Actions\SetFollowUp;
use App\Domain\Leads\Models\Lead;
use App\Domain\Mail\Support\MailFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Leads\AssignLeadRequest;
use App\Http\Requests\Admin\Leads\ContactedRequest;
use App\Http\Requests\Admin\Leads\FollowUpRequest;
use App\Http\Requests\Admin\Leads\LeadNoteRequest;
use App\Http\Requests\Admin\Leads\RejectLeadRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Working a lead: notes, assignment, follow-up, contacted, reject, reopen, archive and restore. One action each.
 */
class LeadActionController extends Controller
{
    public function note(LeadNoteRequest $request, Lead $lead, AddLeadNote $addNote): RedirectResponse
    {
        $addNote->handle($lead, (string) $request->input('body'));

        return back()->with('success', 'Note added.');
    }

    public function assign(AssignLeadRequest $request, Lead $lead, AssignLead $assignLead): RedirectResponse
    {
        $admin = $request->assignee();
        $assignLead->handle($lead, $admin);

        return back()->with('success', $admin === null ? 'Lead unassigned.' : "Assigned to {$admin->name}.");
    }

    public function followUp(FollowUpRequest $request, Lead $lead, SetFollowUp $setFollowUp): RedirectResponse
    {
        $at = $request->at();
        $setFollowUp->handle($lead, $at, $request->input('note'));

        return back()->with('success', $at === null ? 'Follow-up cleared.' : 'Follow-up set for '.MailFormat::dateTime($at).'.');
    }

    public function contacted(ContactedRequest $request, Lead $lead, MarkContacted $markContacted): RedirectResponse
    {
        $markContacted->handle($lead, $request->input('note'), $request->nextFollowUpAt());

        return back()->with('success', 'Marked as contacted.');
    }

    public function reject(RejectLeadRequest $request, Lead $lead, RejectLead $rejectLead): RedirectResponse
    {
        $notify = $request->boolean('notify');
        $rejectLead->handle($lead, (string) $request->input('reason'), $notify);

        return back()->with('success', $notify ? 'Lead rejected. We have emailed them a short note.' : 'Lead rejected.');
    }

    public function reopen(Lead $lead, ReopenLead $reopenLead): RedirectResponse
    {
        $reopenLead->handle($lead);

        return back()->with('success', 'Lead reopened.');
    }

    public function archive(Lead $lead, ArchiveLead $archiveLead): RedirectResponse
    {
        $archiveLead->handle($lead);

        return redirect()->route('admin.leads.index')->with('success', "{$lead->business_name} archived.");
    }

    public function restore(Lead $lead, RestoreLead $restoreLead): RedirectResponse
    {
        $restoreLead->handle($lead);

        return redirect()->route('admin.leads.show', $lead)->with('success', "{$lead->business_name} restored.");
    }
}
