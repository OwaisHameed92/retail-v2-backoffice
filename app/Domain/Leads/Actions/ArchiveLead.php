<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\Enums\LeadNoteKind;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Support\LeadTimeline;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hides a lead from the lists (spam, a test, an exact duplicate). Soft delete: RestoreLead brings it back.
 * Converted leads are the history of a tenant and stay.
 */
class ArchiveLead
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
        if ($lead->isConverted()) {
            throw ValidationException::withMessages(['status' => 'A converted lead is part of the customer’s history and cannot be archived.']);
        }

        if ($lead->trashed()) {
            return $lead;
        }

        return DB::transaction(function () use ($lead) {
            $lead->delete();
            $this->timeline->record($lead, LeadNoteKind::Updated, 'Archived the lead');
            $this->audit->handle('lead.archived', $lead, ['archived' => false], ['archived' => true]);

            return $lead;
        });
    }
}
