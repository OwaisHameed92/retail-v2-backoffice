<?php

namespace App\Domain\Leads\Actions;

use App\Domain\Leads\Data\DuplicateMatch;
use App\Domain\Leads\Data\LeadDetails;
use App\Domain\Leads\Enums\LeadNoteKind;
use App\Domain\Leads\Models\Lead;
use App\Domain\Leads\Support\LeadDuplicates;
use App\Domain\Leads\Support\LeadRules;
use App\Domain\Leads\Support\LeadTimeline;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits a lead's details. A converted lead is history (the tenant holds the live details) and cannot be edited.
 * No-op saves write nothing; a changed email or phone is checked for duplicates again.
 */
class UpdateLead
{
    /** Fields shown on the timeline by their label. */
    private const LABELS = [
        'business_name' => 'business name',
        'contact_name' => 'contact',
        'email' => 'email',
        'phone' => 'phone',
        'town' => 'town',
        'postcode' => 'postcode',
        'shops_count' => 'shops',
        'tills_count' => 'tills',
        'business_type' => 'business type',
        'current_system' => 'current system',
        'message' => 'message',
        'source' => 'source',
        'consent_marketing' => 'marketing consent',
        'utm' => 'campaign',
        'ip' => 'IP address',
    ];

    public function __construct(
        private readonly LeadTimeline $timeline,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Lead $lead, LeadDetails $details): Lead
    {
        if ($lead->isConverted()) {
            throw ValidationException::withMessages(['status' => 'This lead is already a customer. Edit the details on the tenant page instead.']);
        }

        LeadRules::ensureValid($details);

        return DB::transaction(function () use ($lead, $details) {
            $attributes = $details->toAttributes();
            // The public form's IP and campaign are facts about the request; the admin form never sends them.
            unset($attributes['ip'], $attributes['utm']);

            $lead->fill($attributes);
            // In form order, so the timeline reads "Updated email, tills".
            $order = array_flip(array_keys(self::LABELS));
            $dirty = array_keys($lead->getDirty());
            usort($dirty, fn (string $a, string $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

            if ($dirty === []) {
                return $lead;
            }

            $before = array_intersect_key($lead->getOriginal(), array_flip($dirty));
            $after = $lead->only($dirty);
            $lead->save();

            $labels = array_map(fn (string $key) => self::LABELS[$key] ?? str_replace('_', ' ', $key), $dirty);
            $this->timeline->record($lead, LeadNoteKind::Updated, 'Updated '.implode(', ', $labels), ['fields' => $dirty]);
            $this->audit->handle('lead.updated', $lead, $this->scalar($before), $this->scalar($after));

            if (array_intersect($dirty, ['email', 'phone']) !== []) {
                $duplicates = LeadDuplicates::for($lead);

                if ($duplicates !== []) {
                    $this->timeline->record(
                        $lead,
                        LeadNoteKind::Duplicate,
                        'Possible duplicate: '.implode('; ', array_map(fn (DuplicateMatch $match) => $match->describe(), $duplicates)),
                        ['matches' => array_map(fn (DuplicateMatch $match) => $match->toArray(), $duplicates)],
                    );
                }
            }

            return $lead;
        });
    }

    /**
     * Enum values as their strings, for the audit log.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function scalar(array $values): array
    {
        return array_map(fn (mixed $value) => $value instanceof \BackedEnum ? $value->value : $value, $values);
    }
}
