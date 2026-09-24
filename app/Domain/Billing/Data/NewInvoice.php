<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Enums\BillingCycle;
use Carbon\CarbonImmutable;

/**
 * Input of GenerateInvoice. Nulls mean "the company's defaults": the next period, its billing cycle and the
 * configured proration.
 */
final readonly class NewInvoice
{
    public function __construct(
        public ?CarbonImmutable $periodStart = null,
        public ?BillingCycle $cycle = null,
        public ?bool $prorate = null,
        public ?string $notes = null,
        /** Issue (and email) it straight away instead of leaving a draft. */
        public bool $issue = false,
        /** Allow a second invoice for a period another invoice already covers (e.g. a till added mid-period). */
        public bool $allowOverlap = false,
        /** Created by billing:run. */
        public bool $auto = false,
    ) {}
}
