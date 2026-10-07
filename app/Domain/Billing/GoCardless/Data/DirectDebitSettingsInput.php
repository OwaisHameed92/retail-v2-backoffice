<?php

namespace App\Domain\Billing\GoCardless\Data;

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\SetupFeeMethod;

final readonly class DirectDebitSettingsInput
{
    /**
     * @param  string|null  $setupFeeOverride  Net pounds; null = the plan's setup fee.
     * @param  bool  $tillFeeGiven  P11: the form sent the per-added-till fee (older clients do not: it is kept).
     * @param  string|null  $tillSetupFeeOverride  P11: net setup fee per added till; null = the plan's.
     */
    public function __construct(
        public BillingMode $mode,
        public ?string $setupFeeOverride,
        public SetupFeeMethod $setupFeeMethod,
        public int $instalments,
        public bool $tillFeeGiven = false,
        public ?string $tillSetupFeeOverride = null,
    ) {}
}
