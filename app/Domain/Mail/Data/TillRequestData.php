<?php

namespace App\Domain\Mail\Data;

use Carbon\CarbonInterface;

/**
 * A business asked for more tills or another shop from its portal (module 4.7), summarised for staff. Personal
 * details go in the email only, not the email log.
 */
final readonly class TillRequestData
{
    public function __construct(
        public string $businessName,
        public string $companyId,
        /** "More tills" / "Another shop" */
        public string $kind,
        /** "2 more tills for Leeds (LDS)" */
        public string $what,
        public string $requestedBy,
        public string $email,
        public ?string $phone,
        public CarbonInterface $receivedAt,
        public ?string $message = null,
        /** "Asked 2 times": a repeat of an open request. */
        public int $count = 1,
    ) {}
}
