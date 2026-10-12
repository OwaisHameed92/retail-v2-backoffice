<?php

namespace App\Domain\Tenancy\Events;

use App\Domain\Tenancy\Models\Company;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A customer account was closed (CancelCompany), inside its transaction. The partner API tells the partner whose deal
 * made the business (`tenant.cancelled`).
 */
final class CompanyCancelled
{
    use Dispatchable;

    public function __construct(
        public readonly Company $company,
        public readonly string $reason,
    ) {}
}
