<?php

namespace App\Domain\Billing\GoCardless\Data;

use App\Domain\Billing\GoCardless\Enums\MandateStatus;

/** A GoCardless mandate (the customer's Direct Debit authorisation). */
final readonly class GcMandate
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $id,
        public MandateStatus $status,
        public ?string $customerId = null,
        /** Y-m-d: the earliest day a payment can be charged. */
        public ?string $nextPossibleChargeDate = null,
        public array $metadata = [],
    ) {}
}
