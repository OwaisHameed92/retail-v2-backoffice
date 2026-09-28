<?php

namespace App\Domain\Mail\Data;

use Carbon\CarbonInterface;

final readonly class TrialReminderData
{
    /**
     * @param  string|null  $priceSummary  e.g. "£25.00 per till per month". Shown when set.
     */
    public function __construct(
        public string $businessName,
        public string $ownerName,
        public int $daysLeft,
        public CarbonInterface $trialEndsAt,
        public int $tillCount,
        public ?string $priceSummary = null,
        public ?string $companyId = null,
        /** Direct Debit customers without a mandate (module 1.12): our signed link to set it up. */
        public ?string $directDebitUrl = null,
    ) {}
}
