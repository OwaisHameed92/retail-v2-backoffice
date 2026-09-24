<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\Enums\LeadNoteKind;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Support\LeadTimeline;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\Shared\Actions\RecordAudit;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets (or clears, with null) when someone should next get back to the lead. An optional note says why
 * ("Call after 4pm, they are at the cash and carry").
 */
class SetFollowUp
{
    public function __construct(
        private readonly LeadTimeline $timeline,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Lead $lead, ?CarbonInterface $at, ?string $note = null): Lead
    {
        $note = $note === null || trim($note) === '' ? null : trim($note);

        if ($note !== null && mb_strlen($note) > 500) {
            throw ValidationException::withMessages(['note' => 'Keep the reminder under 500 characters.']);
        }

        if ($at === null && $lead->follow_up_at === null) {
            return $lead;
        }

        return DB::transaction(function () use ($lead, $at, $note) {
            $before = $lead->follow_up_at?->toIso8601String();

            $lead->follow_up_at = $at;
            $lead->save();

            $body = $at === null
                ? 'Cleared the follow-up'
                : 'Follow up on '.MailFormat::dateTime($at).($note !== null ? ": {$note}" : '');

            $this->timeline->record($lead, LeadNoteKind::FollowUp, $body, ['follow_up_at' => $at?->toIso8601String()]);
            $this->audit->handle('lead.follow_up_set', $lead, ['follow_up_at' => $before], ['follow_up_at' => $at?->toIso8601String()]);

            return $lead;
        });
    }
}
