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
     * @param  bool|null  $setupFeeByDirectDebit  Owner 2026-11-05: collect the setup fee by Direct Debit; null = keep
     *                                            the business's current choice (older clients, other callers).
     * @param  bool  $depositGiven  Owner 2026-11-11: the form sent the setup fee deposit (other callers do not: it is kept).
     * @param  string|null  $setupFeeDeposit  Net pounds of the first instalment; null = equal instalments.
     */
    public function __construct(
        public BillingMode $mode,
        public ?string $setupFeeOverride,
        public SetupFeeMethod $setupFeeMethod,
        public int $instalments,
        public bool $tillFeeGiven = false,
        public ?string $tillSetupFeeOverride = null,
        public ?bool $setupFeeByDirectDebit = null,
        public bool $depositGiven = false,
        public ?string $setupFeeDeposit = null,
    ) {}
}
