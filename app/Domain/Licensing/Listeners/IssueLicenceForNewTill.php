<?php

namespace App\Domain\Licensing\Listeners;

use App\Domain\Billing\GoCardless\Actions\StartMandateDeadline;
use App\Domain\Licensing\Actions\IssueLicence;
use App\Domain\Licensing\Support\DefaultPlan;
use App\Domain\Licensing\Support\IssuedKeys;
use App\Domain\Tenancy\Events\RegisterAdded;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Every new till gets its licence straight away, in the same transaction as the till. The plain key waits in
 * IssuedKeys for whoever added the till. With no plan at all (a fresh portal) the till stays unlicensed and
 * shows "No licence" with "Issue missing licences" in the admin area.
 *
 * The till gets the plan the business's other live tills are on (DefaultPlan::forNewTill, audit BILL-1), else the
 * company's plan or the portal default. Should the new till start a recurring fee where nothing recurred, a Direct
 * Debit business waiting for its mandate gets a fresh deadline once the till is saved (StartMandateDeadline).
 */
final class IssueLicenceForNewTill
{
    public function __construct(
        private readonly IssueLicence $issueLicence,
        private readonly IssuedKeys $issuedKeys,
        private readonly StartMandateDeadline $deadlines,
    ) {}

    public function handle(RegisterAdded $event): void
    {
        $register = $event->register;
        $company = Company::query()->find($register->company_id);
        $branch = Branch::withoutCompanyScope()->find($register->branch_id);

        if ($company === null || $branch === null || ! $register->is_active || ! $branch->is_active || $company->isCancelled()) {
            return;
        }

        $plan = DefaultPlan::forNewTill($company);

        if ($plan === null) {
            Log::notice('Till added without a licence: no active plan exists yet.', ['register_id' => $register->id, 'company_id' => $company->id]);

            return;
        }

        $recurringBefore = $this->deadlines->before($company);
        $this->issuedKeys->add($this->issueLicence->handle($register, $plan));

        // After the till's transaction: the billing lock is never taken while the till is locked.
        DB::afterCommit(fn () => $this->deadlines->after($company, $recurringBefore, 'tills'));
    }
}
