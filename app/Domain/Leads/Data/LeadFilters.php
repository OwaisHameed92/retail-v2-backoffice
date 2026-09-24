<?php

namespace App\Domain\Leads\Data;

use App\Domain\Leads\Enums\LeadSource;
use App\Domain\Leads\Enums\LeadStatus;
use Illuminate\Http\Request;

/**
 * Filters of the admin lead list and board, read from the query string (unknown values are ignored).
 *
 * - status: a pipeline status, `open` (new + contacted) or `archived`
 * - source: a LeadSource value
 * - assigned: `me`, `none` or an admin id
 * - followUp: `due` (by the end of today, overdue included), `overdue`, `week` (next 7 days), `none`
 */
final readonly class LeadFilters
{
    public const FOLLOW_UP_OPTIONS = ['due', 'overdue', 'week', 'none'];

    public function __construct(
        public ?string $status = null,
        public ?LeadSource $source = null,
        public ?string $assigned = null,
        public ?string $followUp = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $status = (string) $request->string('status');
        $statuses = array_merge(['open', 'archived'], array_map(fn (LeadStatus $s) => $s->value, LeadStatus::pipeline()));
        $assigned = (string) $request->string('assigned');
        $followUp = (string) $request->string('followUp');

        return new self(
            status: in_array($status, $statuses, true) ? $status : null,
            source: LeadSource::tryFrom((string) $request->string('source')),
            assigned: $assigned === '' ? null : mb_substr($assigned, 0, 40),
            followUp: in_array($followUp, self::FOLLOW_UP_OPTIONS, true) ? $followUp : null,
        );
    }

    public function isEmpty(): bool
    {
        return $this->status === null && $this->source === null && $this->assigned === null && $this->followUp === null;
    }

    /**
     * @return array{status: string|null, source: string|null, assigned: string|null, followUp: string|null}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'source' => $this->source?->value,
            'assigned' => $this->assigned,
            'followUp' => $this->followUp,
        ];
    }
}
