<?php

namespace App\Domain\Leads\Enums;

/**
 * Where a trial request is in our sales process. camelCase values (contract convention).
 *
 * Transitions (App\Domain\Leads\Actions):
 * - MarkContacted: new | contacted → contacted
 * - RejectLead:    new | contacted → rejected (reason required)
 * - ReopenLead:    rejected        → contacted (or new when nobody ever contacted them)
 * - ApproveTrial:  new | contacted → converted (creates the tenant in the same transaction)
 *
 * `approved` is part of the agreed value list but no action sets it: approving creates the tenant at once,
 * so the lead goes straight to `converted`. The admin filters leave it out.
 */
enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Converted = 'converted';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Contacted => 'Contacted',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Converted => 'Converted',
        };
    }

    /** Still being worked: can be contacted, rejected or approved. */
    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::Contacted], true);
    }

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::New, self::Contacted];
    }

    /**
     * Statuses offered in filters and on the board, in pipeline order.
     *
     * @return list<self>
     */
    public static function pipeline(): array
    {
        return [self::New, self::Contacted, self::Converted, self::Rejected];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status) => ['value' => $status->value, 'label' => $status->label()], self::pipeline());
    }
}
