<?php

namespace App\Domain\Billing\GoCardless\Data;

use Carbon\CarbonImmutable;

/** A hosted Direct Debit setup: the billing request and the GoCardless page the customer fills in. */
final readonly class GcSetupFlow
{
    public function __construct(
        public string $billingRequestId,
        public string $url,
        public ?CarbonImmutable $expiresAt = null,
    ) {}
}
