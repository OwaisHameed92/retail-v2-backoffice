<?php

namespace App\Domain\Billing\GoCardless\Data;

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\SetupFeeMethod;

final readonly class DirectDebitSettingsInput
{
    /**
     * @param  string|null  $setupFeeOverride  Net pounds; null = the plan's setup fee.
     */
    public function __construct(
        public BillingMode $mode,
        public ?string $setupFeeOverride,
        public SetupFeeMethod $setupFeeMethod,
        public int $instalments,
    ) {}
}
