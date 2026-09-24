<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\Enums\LeadNoteKind;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Support\LeadTimeline;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes a rejected lead back into the pipeline (they called back, or it was rejected by mistake): contacted if we
 * ever spoke to them, else new. The old reason stays on the timeline and in the audit log.
 */
class ReopenLead
{
    public function __construct(
        private readonly LeadTimeline $timeline,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Lead $lead): Lead
    {
        if ($lead->status !== LeadStatus::Rejected) {
            throw ValidationException::withMessages(['status' => 'Only rejected leads can be reopened.']);
        }

        return DB::transaction(function () use ($lead) {
            $to = $lead->contacted_at !== null ? LeadStatus::Contacted : LeadStatus::New;
            $reason = $lead->rejection_reason;

            $lead->status = $to;
            $lead->rejection_reason = null;
            $lead->rejected_at = null;
            $lead->save();

            $this->timeline->record($lead, LeadNoteKind::StatusChanged, 'Reopened as '.mb_strtolower($to->label()), [
                'from' => LeadStatus::Rejected->value,
                'to' => $to->value,
            ]);
            $this->audit->handle('lead.reopened', $lead, ['status' => LeadStatus::Rejected->value, 'rejection_reason' => $reason], ['status' => $to->value]);

            return $lead;
        });
    }
}
