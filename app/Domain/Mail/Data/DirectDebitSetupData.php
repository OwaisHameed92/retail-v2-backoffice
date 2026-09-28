<?php

namespace App\Domain\Mail\Data;

final readonly class DirectDebitSetupData
{
    /**
     * @param  string  $setupUrl  Our signed link that opens the GoCardless page (never logged).
     * @param  string|null  $setupFee  Gross, pounds ("418.80"); null when there is no setup fee to collect.
     * @param  string|null  $recurring  Gross per cycle, pounds; null when no tills are billed yet.
     * @param  string  $per  "per month" / "per year"
     */
    public function __construct(
        public string $businessName,
        public string $ownerName,
        public string $setupUrl,
        public ?string $setupFee = null,
        public int $setupInstalments = 1,
        public ?string $recurring = null,
        public string $per = 'per month',
        public int $tillCount = 0,
        public ?string $companyId = null,
    ) {}
}
