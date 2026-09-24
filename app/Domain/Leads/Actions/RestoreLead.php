<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\Enums\LeadNoteKind;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Support\LeadTimeline;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Support\Facades\DB;

/**
 * Brings an archived lead back into the lists with the status it had.
 */
class RestoreLead
{
    public function __construct(
        private readonly LeadTimeline $timeline,
        private readonly RecordAudit $audit,
    ) {}

    public function handle(Lead $lead): Lead
    {
        if (! $lead->trashed()) {
            return $lead;
        }

        return DB::transaction(function () use ($lead) {
            $lead->restore();
            $this->timeline->record($lead, LeadNoteKind::Updated, 'Restored the lead');
            $this->audit->handle('lead.restored', $lead, ['archived' => true], ['archived' => false]);

            return $lead;
        });
    }
}
