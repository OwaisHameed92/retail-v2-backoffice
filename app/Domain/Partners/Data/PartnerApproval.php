<?php

namespace App\Domain\Partners\Data;

use App\Domain\Tenancy\Data\NewTenant;

/**
 * What "Approve" creates, as the admin confirmed it on the review form: the business exactly as the new-business
 * wizard would (NewTenant: company, shops with their tills, owner, plan, licence form), plus the agreed setup fee per
 * till (net; null = the plan's) and whether VAT is charged.
 */
final readonly class PartnerApproval
{
    public function __construct(
        public NewTenant $tenant,
        public ?string $setupFeePerTill = null,
        public bool $vatApplies = true,
    ) {}
}
