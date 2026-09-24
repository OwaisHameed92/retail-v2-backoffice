<?php

namespace App\Domain\Tenancy\Events;

use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A customer was set up by CreateTenant (company, first branch and tills, owner). Dispatched at the end of the
 * action's transaction: module 1.3 queues the welcome email with the tills' licence keys (sent after commit).
 */
final class TenantCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Company $company,
        public readonly User $owner,
    ) {}
}
