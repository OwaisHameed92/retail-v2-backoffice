<?php

namespace App\Domain\Billing\GoCardless\Listeners;

use App\Domain\Billing\GoCardless\Actions\SyncSubscription;
use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\Tenancy\Events\BranchAdded;
use App\Domain\Tenancy\Events\BranchDeactivated;
use App\Domain\Tenancy\Events\BranchReactivated;
use App\Domain\Tenancy\Events\RegisterAdded;
use App\Domain\Tenancy\Events\RegisterDeactivated;
use App\Domain\Tenancy\Events\RegisterReactivated;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * A till or a branch was added, deactivated or reactivated: the Direct Debit subscription follows the live tills
 * (per till) or active branches (per branch, module 1.13) from its next payment (SyncSubscription, audited). Queued after the till's transaction commits, so GoCardless is never
 * called inside it. Other changes (plan price, a revoked licence) are caught by the daily reconcile.
 */
final class SyncDirectDebitOnTillChange implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly SyncSubscription $syncSubscription) {}

    public function handle(RegisterAdded|RegisterDeactivated|RegisterReactivated|BranchAdded|BranchDeactivated|BranchReactivated $event): void
    {
        $companyId = $event instanceof BranchAdded || $event instanceof BranchDeactivated || $event instanceof BranchReactivated
            ? $event->branch->company_id
            : $event->register->company_id;
        $company = Company::query()->find($companyId);

        if ($company === null) {
            return;
        }

        try {
            $this->syncSubscription->handle($company, str_starts_with(class_basename($event), 'Branch') ? 'branches' : 'tills');
        } catch (GoCardlessException $exception) {
            Log::warning('Direct Debit amount not updated after a till change', ['company_id' => $company->id, 'error' => $exception->getMessage()]);
        }
    }
}
