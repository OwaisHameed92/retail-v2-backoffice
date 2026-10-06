<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Data\UpfrontPayment;
use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\MandateDeadline;
use App\Domain\Billing\Support\ManualCollection;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Billing for a business staff just onboarded (admin wizard or trial approval, module 1.13): it pays by Direct
 * Debit, which the owner sets up in the portal within `billing.direct_debit.mandate_deadline_days` (else
 * billing:run suspends it), and the upfront payment is recorded when staff took one. Without an upfront payment
 * the setup fee (if any) is collected by Direct Debit once the mandate exists.
 *
 * Manual collection (Pakistan plan P5): no Direct Debit, so no mandate deadline; the business pays each period's
 * invoice by hand (billing mode upfrontCash) and billing:run issues those invoices.
 */
class OnboardTenantBilling
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly RecordUpfrontPayment $recordUpfront,
        private readonly RecordAudit $audit,
    ) {}

    public function handle(Company $company, ?UpfrontPayment $upfront = null): BillingAccount
    {
        if (ManualCollection::active()) {
            return $this->manual($company, $upfront);
        }

        DB::transaction(function () use ($company) {
            $account = $this->accounts->lock($company);
            $account->billing_mode = BillingMode::DirectDebit;
            $account->mandate_deadline_at = MandateDeadline::fromNow();
            $account->save();

            $this->audit->handle('billing.onboarded', $account, null, [
                'billing_mode' => BillingMode::DirectDebit->value,
                'mandate_deadline_at' => $account->mandate_deadline_at->toIso8601String(),
            ], companyId: $company->id);
        });

        if ($upfront !== null) {
            $this->recordUpfront->handle($company, $upfront);
        }

        return $this->accounts->for($company);
    }

    /** Pakistan plan P5: invoices paid by hand, no Direct Debit and no mandate deadline. */
    private function manual(Company $company, ?UpfrontPayment $upfront): BillingAccount
    {
        DB::transaction(function () use ($company) {
            $account = $this->accounts->lock($company);
            $account->billing_mode = BillingMode::UpfrontCash;
            $account->mandate_deadline_at = null;
            $account->save();

            $this->audit->handle('billing.onboarded', $account, null, [
                'billing_mode' => BillingMode::UpfrontCash->value,
                'collection' => 'manual',
            ], companyId: $company->id);
        });

        if ($upfront !== null) {
            $this->recordUpfront->handle($company, $upfront);
        }

        return $this->accounts->for($company);
    }
}
