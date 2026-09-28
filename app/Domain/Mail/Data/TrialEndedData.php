<?php

namespace App\Domain\Mail\Data;

use Carbon\CarbonInterface;

final readonly class TrialEndedData
{
    public function __construct(
        public string $businessName,
        public string $ownerName,
        public CarbonInterface $endedAt,
        public int $tillCount,
        public ?string $priceSummary = null,
        public ?string $companyId = null,
        /** Direct Debit customers without a mandate (module 1.12): our signed link to set it up. */
        public ?string $directDebitUrl = null,
    ) {}
}
