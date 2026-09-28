<?php

namespace App\Domain\Mail\Data;

use Carbon\CarbonInterface;

final readonly class DirectDebitCancelledData
{
    /**
     * @param  string  $status  Plain status, e.g. "Cancelled" or "Expired".
     * @param  string|null  $setupUrl  Signed link to set up a new Direct Debit (never logged).
     */
    public function __construct(
        public string $businessName,
        public string $ownerName,
        public string $status,
        public CarbonInterface $graceUntil,
        public ?string $setupUrl = null,
        public ?string $companyId = null,
    ) {}
}
