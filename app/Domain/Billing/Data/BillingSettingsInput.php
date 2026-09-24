<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Enums\BillingCycle;

final readonly class BillingSettingsInput
{
    /**
     * @param  list<string>  $emails  Empty = the company's active owners.
     */
    public function __construct(
        public ?string $billingName,
        public ?string $billingAddress,
        public array $emails,
        public BillingCycle $cycle,
        public int $paymentTermsDays,
        public bool $vatApplies,
    ) {}
}
