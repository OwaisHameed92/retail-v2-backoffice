<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\Enums\LeadNoteKind;
use App\Domain\Leads\Enums\LeadStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Support\LeadTimeline;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\Shared\Actions\RecordAudit;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records that we spoke to (or tried to reach) the prospect: new → contacted, or "contacted again".
 *
 * The call that was due is done, so a follow-up that is already due is cleared, unless the next one is given.
 */
class MarkContacted
{
    public function __construct(
        private readonly LeadTimeline $timeline,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Lead $lead, ?string $note = null, ?CarbonInterface $nextFollowUpAt = null): Lead
    {
        if (! $lead->isOpen()) {
            throw ValidationException::withMessages(['status' => "{$lead->business_name} is ".mb_strtolower($lead->status->label()).'. Only new or contacted leads can be marked as contacted.']);
        }

        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($note !== null && mb_strlen($note) > 2000) {
            throw ValidationException::withMessages(['note' => 'Keep the note under 2,000 characters.']);
        }

        return DB::transaction(function () use ($lead, $note, $nextFollowUpAt) {
            $from = $lead->status;
            $now = now();

            $lead->status = LeadStatus::Contacted;
            $lead->contacted_at ??= $now;
            $lead->last_contacted_at = $now;

            if ($nextFollowUpAt !== null) {
                $lead->follow_up_at = $nextFollowUpAt;
            } elseif ($lead->follow_up_at !== null && $lead->follow_up_at->lte($now)) {
                $lead->follow_up_at = null;
            }

            $lead->save();

            $body = $from === LeadStatus::New ? 'Marked as contacted' : 'Contacted again';
            if ($nextFollowUpAt !== null) {
                $body .= '. Next follow-up '.MailFormat::dateTime($nextFollowUpAt);
            }
            if ($note !== null) {
                $body .= ": {$note}";
            }

            $this->timeline->record($lead, LeadNoteKind::StatusChanged, $body, ['from' => $from->value, 'to' => LeadStatus::Contacted->value]);
            $this->audit->handle('lead.contacted', $lead, ['status' => $from->value], ['status' => LeadStatus::Contacted->value], array_filter([
                'next_follow_up_at' => $nextFollowUpAt?->toIso8601String(),
            ]));

            return $lead;
        });
    }
}
