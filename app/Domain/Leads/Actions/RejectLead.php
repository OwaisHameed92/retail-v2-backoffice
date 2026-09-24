<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\Enums\LeadNoteKind;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Support\LeadTimeline;
use App\Domain\Mail\Data\LeadRejectedData;
use App\Domain\Mail\Mailables\LeadRejectedMail;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Turns down a trial request. The reason is internal (timeline and audit log only). Optionally the prospect gets
 * a short, polite LeadRejectedMail that never includes the internal reason. The follow-up is cleared.
 */
class RejectLead
{
    public function __construct(
        private readonly LeadTimeline $timeline,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Lead $lead, string $reason, bool $notifyProspect = false): Lead
    {
        $reason = trim($reason);

        if (! $lead->isOpen()) {
            throw ValidationException::withMessages(['status' => "{$lead->business_name} is ".mb_strtolower($lead->status->label()).'. Only new or contacted leads can be rejected.']);
        }

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Say briefly why (3 to 500 characters). Only staff see it.']);
        }

        if ($notifyProspect && $lead->email === null) {
            throw ValidationException::withMessages(['notify' => 'This lead has no email address, so we cannot email them.']);
        }

        return DB::transaction(function () use ($lead, $reason, $notifyProspect) {
            $from = $lead->status;

            $lead->status = LeadStatus::Rejected;
            $lead->rejection_reason = $reason;
            $lead->rejected_at = now();
            $lead->follow_up_at = null;
            $lead->save();

            $this->timeline->record(
                $lead,
                LeadNoteKind::StatusChanged,
                "Rejected: {$reason}".($notifyProspect ? ' (emailed the prospect)' : ''),
                ['from' => $from->value, 'to' => LeadStatus::Rejected->value, 'emailed' => $notifyProspect],
            );

            $this->audit->handle('lead.rejected', $lead, ['status' => $from->value], ['status' => LeadStatus::Rejected->value], [
                'reason' => $reason,
                'emailed' => $notifyProspect,
            ]);

            if ($notifyProspect) {
                Mail::to((string) $lead->email, $lead->contact_name)->queue(new LeadRejectedMail(new LeadRejectedData(
                    contactName: $lead->contact_name,
                    businessName: $lead->business_name,
                )));
            }

            return $lead;
        });
    }
}
