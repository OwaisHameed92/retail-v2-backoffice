<?php

namespace App\Domain\Billing\Data;

use App\Domain\Plans\Enums\PricingMode;

/**
 * A company's own pricing (module 1.13). Every field null = the plan's: its mode and its monthly / yearly price per
 * unit (net, pounds).
 */
final readonly class PricingOverride
{
    public function __construct(
        public ?PricingMode $mode = null,
        public ?string $priceMonthly = null,
        public ?string $priceYearly = null,
    ) {}
}
