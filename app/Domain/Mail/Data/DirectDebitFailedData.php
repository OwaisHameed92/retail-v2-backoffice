<?php

namespace App\Domain\Mail\Data;

use Carbon\CarbonInterface;

final readonly class DirectDebitFailedData
{
    /**
     * @param  string  $amount  Pounds, e.g. "60.00".
     * @param  string|null  $reason  GoCardless' plain reason, e.g. "The payer's bank account had insufficient funds."
     * @param  list<string>  $bankDetails  Our bank details for paying by transfer instead.
     */
    public function __construct(
        public string $businessName,
        public string $ownerName,
        public string $amount,
        public ?string $invoiceNumber,
        public ?CarbonInterface $chargeDate,
        public ?string $reason = null,
        public bool $chargedBack = false,
        public bool $reminder = false,
        public array $bankDetails = [],
        public ?string $companyId = null,
    ) {}
}
