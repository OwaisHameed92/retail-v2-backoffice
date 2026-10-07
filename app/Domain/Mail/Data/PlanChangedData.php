<?php

namespace App\Domain\Mail\Data;

use Carbon\CarbonInterface;

/**
 * "Your plan has changed" (change plan, owner 2026-10-07). `changes` are the customer's sentences (features, setup
 * fee, monthly fee, licences); `directDebitUrl` is the portal billing page when a Direct Debit must be set up by
 * `directDebitBy` (UK only); `howToPay` says how to pay by hand where fees are paid that way (Pakistan).
 */
final readonly class PlanChangedData
{
    /**
     * @param  list<string>  $changes
     */
    public function __construct(
        public string $businessName,
        public string $ownerName,
        public ?string $fromPlan,
        public string $toPlan,
        public CarbonInterface $changedOn,
        public array $changes,
        public ?string $setupFee = null,
        public ?string $setupInvoice = null,
        public ?string $directDebitUrl = null,
        public ?CarbonInterface $directDebitBy = null,
        public ?string $howToPay = null,
        public ?string $companyId = null,
    ) {}
}
