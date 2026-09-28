<?php

namespace App\Domain\Billing\GoCardless\Data;

/** A GoCardless billing request: `fulfilled` once the customer has authorised the mandate. */
final readonly class GcBillingRequest
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $id,
        public string $status,
        public ?string $customerId = null,
        public ?string $mandateId = null,
        public array $metadata = [],
    ) {}
}
