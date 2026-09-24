<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Admin\Models\Admin;
use App\Domain\Leads\Data\DuplicateMatch;
use App\Domain\Leads\Data\LeadDetails;
use App\Domain\Leads\Enums\LeadNoteKind;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Support\LeadDuplicates;
use App\Domain\Leads\Support\LeadRules;
use App\Domain\Leads\Support\LeadTimeline;
use App\Domain\Mail\Data\NewLeadData;
use App\Domain\Mail\Mailables\AdminNewLeadMail;
use App\Domain\Shared\Actions\RecordAudit;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Records a trial request: from the admin "Add lead" form, and from the public trial form (module 1.10, where no
 * admin is signed in). Flags possible duplicates on the timeline and emails the staff alert (AdminNewLeadMail,
 * to `sspos.lead_alert_emails`, queued after commit).
 */
class CreateLead
{
    public function __construct(
        private readonly LeadTimeline $timeline,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(LeadDetails $details, ?Admin $assignTo = null, ?CarbonInterface $followUpAt = null): Lead
    {
        LeadRules::ensureValid($details);

        if ($assignTo !== null) {
            AssignLead::ensureAssignable($assignTo);
        }

        return DB::transaction(function () use ($details, $assignTo, $followUpAt) {
            $lead = new Lead($details->toAttributes());
            $lead->status = LeadStatus::New;
            $lead->assigned_admin_id = $assignTo?->id;
            $lead->follow_up_at = $followUpAt;
            $lead->save();

            $admin = $this->timeline->currentAdmin();

            $this->timeline->record($lead, LeadNoteKind::Created, $admin === null
                ? 'Trial request received from the '.mb_strtolower($lead->source->label())
                : 'Added the lead ('.mb_strtolower($lead->source->label()).')');

            if ($assignTo !== null) {
                $this->timeline->record($lead, LeadNoteKind::Assigned, "Assigned to {$assignTo->name}", ['admin_id' => $assignTo->id]);
            }

            $duplicates = LeadDuplicates::for($lead);

            if ($duplicates !== []) {
                $this->timeline->record(
                    $lead,
                    LeadNoteKind::Duplicate,
                    'Possible duplicate: '.implode('; ', array_map(fn (DuplicateMatch $match) => $match->describe(), $duplicates)),
                    ['matches' => array_map(fn (DuplicateMatch $match) => $match->toArray(), $duplicates)],
                );
            }

            $this->audit->handle('lead.created', $lead, null, [
                'business_name' => $lead->business_name,
                'source' => $lead->source->value,
                'shops_count' => $lead->shops_count,
                'tills_count' => $lead->tills_count,
                'assigned_admin_id' => $lead->assigned_admin_id,
            ], ['possible_duplicates' => count($duplicates)]);

            Mail::queue(new AdminNewLeadMail(new NewLeadData(
                contactName: $lead->contact_name,
                businessName: $lead->business_name,
                email: $lead->email ?? 'Not given',
                phone: $lead->phone,
                shops: $lead->shops_count,
                tills: $lead->tills_count,
                receivedAt: $lead->created_at ?? now(),
                message: $lead->message,
                leadId: $lead->id,
                possibleDuplicate: $duplicates === [] ? null : ucfirst($duplicates[0]->describe()).(count($duplicates) > 1 ? ' and '.(count($duplicates) - 1).' more' : ''),
                addedBy: $admin?->name,
            )));

            return $lead;
        });
    }
}
