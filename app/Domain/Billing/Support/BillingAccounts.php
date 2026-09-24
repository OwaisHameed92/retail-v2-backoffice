<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The billing settings row of a company. `for()` reads it (a company that never had one gets the defaults,
 * unsaved, so reading a page never writes); `lock()` creates it when missing and locks it: the per-company lock
 * every money action takes first (issue, pay, void, credit, run), so they never interleave.
 */
final class BillingAccounts
{
    public function for(Company $company): BillingAccount
    {
        $account = BillingAccount::withoutCompanyScope()->where('company_id', $company->id)->first() ?? $this->defaults($company);

        return $account->setRelation('company', $company);
    }

    /** The company's billing row (created when missing), locked for update. Call inside a transaction. */
    public function lock(Company $company): BillingAccount
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Lock the billing account inside a transaction.');
        }

        $this->ensureExists($company);

        return BillingAccount::withoutCompanyScope()->where('company_id', $company->id)->lockForUpdate()->firstOrFail()->setRelation('company', $company);
    }

    private function ensureExists(Company $company): void
    {
        if (BillingAccount::withoutCompanyScope()->where('company_id', $company->id)->exists()) {
            return;
        }

        try {
            // A savepoint, so a concurrent insert does not abort the caller's transaction.
            DB::transaction(fn () => $this->defaults($company)->save());
        } catch (UniqueConstraintViolationException) {
            // Created by a concurrent request: that one is used.
        }
    }

    private function defaults(Company $company): BillingAccount
    {
        $account = new BillingAccount([
            'payment_terms_days' => max(0, (int) config('billing.payment_terms_days', 7)),
        ]);
        $account->company_id = $company->id;

        return $account;
    }
}
