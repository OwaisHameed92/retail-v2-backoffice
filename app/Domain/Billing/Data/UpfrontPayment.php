<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Enums\PaymentMethod;
use Carbon\CarbonImmutable;

/**
 * The upfront payment staff take when they onboard a business (module 1.13): the setup fee (net, pounds; null =
 * the plan's, "0" = nothing to pay), paid in cash or by bank transfer.
 */
final readonly class UpfrontPayment
{
    public function __construct(
        public ?string $setupFee,
        public PaymentMethod $method,
        public ?string $reference = null,
        public ?CarbonImmutable $receivedAt = null,
    ) {}
}
