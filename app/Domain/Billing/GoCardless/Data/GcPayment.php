<?php

namespace App\Domain\Billing\GoCardless\Data;

use App\Domain\Billing\GoCardless\Enums\PaymentStatus;

/** A GoCardless payment. Amounts are pence (GoCardless' unit); our records use pounds. */
final readonly class GcPayment
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $id,
        public int $amountPence,
        public PaymentStatus $status,
        /** Y-m-d */
        public ?string $chargeDate = null,
        public ?string $mandateId = null,
        public ?string $subscriptionId = null,
        public ?string $description = null,
        public array $metadata = [],
    ) {}
}
