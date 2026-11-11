<?php

namespace App\Domain\Mail\Data;

use Carbon\CarbonInterface;

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
        /** When the tills lock without a mandate (the reminder before the deadline); null = not shown. */
        public ?CarbonInterface $deadline = null,
        /** The reminder billing:run sends before the deadline (other subject line). */
        public bool $reminder = false,
        /** Owner 2026-11-05: staff chose to collect this business's setup fee by this Direct Debit too. */
        public bool $setupFeeByDirectDebit = false,
        /** Owner 2026-11-11: an unpaid setup fee that starts with a deposit, "£420.00 deposit, then 11 × £81.60". */
        public ?string $setupFeeSplit = null,
    ) {}
}
