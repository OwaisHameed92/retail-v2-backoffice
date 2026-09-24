<?php

namespace App\Domain\Leads\Data;

/**
 * Numbers from LeadStats. `toArray()` is the shape the lead list (and the 1.9 dashboard) sends to React.
 */
final readonly class LeadStatsData
{
    public function __construct(
        public int $newThisWeek,
        public int $awaitingContact,
        public int $followUpsDue,
        public int $overdueFollowUps,
        public ?float $conversionRate,
        public int $receivedInWindow,
        public int $convertedInWindow,
        public int $windowDays,
    ) {}

    /**
     * @return array{newThisWeek: int, awaitingContact: int, followUpsDue: int, overdueFollowUps: int, conversionRate: float|null, receivedInWindow: int, convertedInWindow: int, windowDays: int}
     */
    public function toArray(): array
    {
        return [
            'newThisWeek' => $this->newThisWeek,
            'awaitingContact' => $this->awaitingContact,
            'followUpsDue' => $this->followUpsDue,
            'overdueFollowUps' => $this->overdueFollowUps,
            'conversionRate' => $this->conversionRate,
            'receivedInWindow' => $this->receivedInWindow,
            'convertedInWindow' => $this->convertedInWindow,
            'windowDays' => $this->windowDays,
        ];
    }
}
