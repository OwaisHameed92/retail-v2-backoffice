<?php

namespace App\Domain\Mail\Data;

use Carbon\CarbonInterface;

/**
 * A new trial request, summarised for staff. Personal details go in the email only, not the email log.
 */
final readonly class NewLeadData
{
    public function __construct(
        public string $contactName,
        public string $businessName,
        public string $email,
        public ?string $phone,
        public int $shops,
        public int $tills,
        public CarbonInterface $receivedAt,
        public ?string $message = null,
        public ?string $leadId = null,
        /** "Same email as tenant Khan Mini Mart" (module 1.6 duplicate check). */
        public ?string $possibleDuplicate = null,
        /** Staff member who added the lead in the admin area; null for the public trial form. */
        public ?string $addedBy = null,
    ) {}
}
