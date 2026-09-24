<?php

namespace App\Domain\Mail\Data;

use Carbon\CarbonInterface;

final readonly class AccountSuspendedData
{
    /**
     * @param  string  $reason  Plain sentence shown to the customer, e.g. "Your invoice INV-0042 is 14 days overdue."
     * @param  string|null  $howToFix  Plain sentence; a default about paying and contacting us is used when null.
     * @param  string|null  $amountDue  Pounds as a decimal string, e.g. "150.00".
     */
    public function __construct(
        public string $businessName,
        public string $ownerName,
        public string $reason,
        public CarbonInterface $suspendedAt,
        public ?string $howToFix = null,
        public ?string $amountDue = null,
        public ?string $companyId = null,
    ) {}
}
