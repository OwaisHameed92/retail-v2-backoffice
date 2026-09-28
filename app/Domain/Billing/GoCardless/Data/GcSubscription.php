<?php

namespace App\Domain\Billing\GoCardless\Data;

use App\Domain\Billing\GoCardless\Enums\SubscriptionStatus;

/** A GoCardless subscription: a fixed amount collected every month or year. */
final readonly class GcSubscription
{
    /**
     * @param  'monthly'|'yearly'  $intervalUnit
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $id,
        public int $amountPence,
        public string $intervalUnit,
        public SubscriptionStatus $status,
        public ?string $mandateId = null,
        /** Y-m-d of the next payment GoCardless will charge, when one is scheduled. */
        public ?string $upcomingChargeDate = null,
        public array $metadata = [],
    ) {}
}
