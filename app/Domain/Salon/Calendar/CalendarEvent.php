<?php

namespace App\Domain\Salon\Calendar;

use Carbon\CarbonImmutable;

/**
 * One VEVENT of a staff calendar feed: one appointment service row. Carries no phone, e-mail, notes, price or health
 * data by construction (StylistCalendar decides what goes in).
 */
final readonly class CalendarEvent
{
    public function __construct(
        public string $uid,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public string $summary,
        public bool $cancelled,
        public ?CarbonImmutable $lastModified,
        public ?string $description = null,
        public ?string $location = null,
    ) {}
}
