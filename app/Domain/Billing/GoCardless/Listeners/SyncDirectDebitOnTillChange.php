<?php

namespace App\Domain\Billing\GoCardless\Listeners;

use App\Domain\Billing\GoCardless\Actions\SyncSubscription;
use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\Tenancy\Events\RegisterAdded;
use App\Domain\Tenancy\Events\RegisterDeactivated;
use App\Domain\Tenancy\Events\RegisterReactivated;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * A till was added, deactivated or reactivated: the Direct Debit subscription follows the live tills from its
 * next payment (SyncSubscription, audited). Queued after the till's transaction commits, so GoCardless is never
 * called inside it. Other changes (plan price, a revoked licence) are caught by the daily reconcile.
 */
final class SyncDirectDebitOnTillChange implements ShouldQueue
{
    public bool $afterCommit = true;

    public function __construct(private readonly SyncSubscription $syncSubscription) {}

    public function handle(RegisterAdded|RegisterDeactivated|RegisterReactivated $event): void
    {
        $company = Company::query()->find($event->register->company_id);

        if ($company === null) {
            return;
        }

        try {
            $this->syncSubscription->handle($company, 'tills');
        } catch (GoCardlessException $exception) {
            Log::warning('Direct Debit amount not updated after a till change', ['company_id' => $company->id, 'error' => $exception->getMessage()]);
        }
    }
}
