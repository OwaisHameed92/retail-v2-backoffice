<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\Enums\LeadNoteKind;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Models\LeadNote;
use App\Domain\Leads\Support\LeadTimeline;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Country\MoneyFormat;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A note typed by staff on the lead's timeline ("Called, owner is away until Monday"). Allowed at every status,
 * converted leads included. The audit entry records that a note was added, not its text.
 */
class AddLeadNote
{
    public function __construct(
        private readonly LeadTimeline $timeline,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Lead $lead, string $body): LeadNote
    {
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Write a note first.']);
        }

        if (mb_strlen($body) > LeadNote::MAX_LENGTH) {
            throw ValidationException::withMessages(['body' => 'Keep notes under '.MoneyFormat::number(LeadNote::MAX_LENGTH).' characters.']);
        }

        return DB::transaction(function () use ($lead, $body) {
            $note = $this->timeline->record($lead, LeadNoteKind::Note, $body);

            $this->audit->handle('lead.note_added', $lead, null, null, ['note_id' => $note->id, 'length' => mb_strlen($body)]);

            return $note;
        });
    }
}
