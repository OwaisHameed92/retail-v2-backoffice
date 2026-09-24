<?php

namespace App\Domain\Mail\Data;

use Carbon\CarbonInterface;

final readonly class LicenceRenewedData
{
    /**
     * @param  list<RenewedTillData>  $tills
     * @param  string|null  $amountPaid  Pounds as a decimal string, e.g. "150.00".
     * @param  string|null  $reference  Invoice or receipt number.
     */
    public function __construct(
        public string $businessName,
        public string $ownerName,
        public array $tills,
        public CarbonInterface $newExpiry,
        public ?string $amountPaid = null,
        public ?string $reference = null,
        public ?string $companyId = null,
    ) {}
}
