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
 *
 * With `$mergeIntoOpenLead` (public form) a request whose email or phone matches an open lead (new or contacted)
 * becomes a `duplicate` note on that lead instead of a second lead; the existing lead is returned
 * (`wasRecentlyCreated` false) and staff are still alerted.
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
    public function handle(
        LeadDetails $details,
        ?Admin $assignTo = null,
        ?CarbonInterface $followUpAt = null,
        bool $mergeIntoOpenLead = false,
    ): Lead {
        LeadRules::ensureValid($details);

        if ($assignTo !== null) {
            AssignLead::ensureAssignable($assignTo);
        }

        return DB::transaction(function () use ($details, $assignTo, $followUpAt, $mergeIntoOpenLead) {
            $open = $mergeIntoOpenLead ? LeadDuplicates::openLeadMatching($details) : null;

            if ($open !== null) {
                return $this->repeatRequest($open, $details);
            }

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

            $this->alertStaff($lead, $details, $duplicates === []
                ? null
                : ucfirst($duplicates[0]->describe()).(count($duplicates) > 1 ? ' and '.(count($duplicates) - 1).' more' : ''), $admin);

            return $lead;
        });
    }

    /** A repeat request for an open lead: one `duplicate` note with what was asked this time, no second lead. */
    private function repeatRequest(Lead $lead, LeadDetails $details): Lead
    {
        $contact = implode(', ', array_filter([$details->contactName, $details->email, $details->phone]));

        $this->timeline->record($lead, LeadNoteKind::Duplicate, sprintf(
            'Asked for a trial again from the %s: %s · %d %s, %d %s',
            mb_strtolower($details->source->label()),
            $contact,
            $details->shopsCount,
            $details->shopsCount === 1 ? 'shop' : 'shops',
            $details->tillsCount,
            $details->tillsCount === 1 ? 'till' : 'tills',
        ), array_filter([
            'repeat' => true,
            'source' => $details->source->value,
            'shops_count' => $details->shopsCount,
            'tills_count' => $details->tillsCount,
            'utm' => $details->utm === [] ? null : $details->utm,
        ], fn ($value) => $value !== null));

        $this->audit->handle('lead.repeat_request', $lead, null, null, ['source' => $details->source->value]);

        $this->alertStaff($lead, $details, 'Repeat request: added as a note to the open lead '.$lead->business_name
            .' ('.$lead->status->value.')', $this->timeline->currentAdmin());

        return $lead;
    }

    private function alertStaff(Lead $lead, LeadDetails $details, ?string $possibleDuplicate, ?Admin $admin): void
    {
        Mail::queue(new AdminNewLeadMail(new NewLeadData(
            contactName: $details->contactName,
            businessName: $details->businessName,
            email: $details->email ?? 'Not given',
            phone: $details->phone,
            shops: $details->shopsCount,
            tills: $details->tillsCount,
            receivedAt: now(),
            message: $details->message,
            leadId: $lead->id,
            possibleDuplicate: $possibleDuplicate,
            addedBy: $admin?->name,
        )));
    }
}
