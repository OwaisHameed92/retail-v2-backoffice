<?php

namespace App\Domain\Billing\GoCardless\Actions;

use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Log;

/**
 * GoCardless sent the owner back to the portal (module 1.13): try to finish at once (CompleteMandateSetup; the
 * `billing_requests.fulfilled` webhook finishes it anyway). Returns whether the Direct Debit is now set up.
 */
class FinishOwnerMandateSetup
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly CompleteMandateSetup $completeMandateSetup,
    ) {}

    public function handle(Company $company): bool
    {
        $account = $this->accounts->for($company);

        if (! $account->hasUsableMandate() && $account->gc_billing_request_id !== null) {
            try {
                $this->completeMandateSetup->handle($company, $account->gc_billing_request_id);
            } catch (GoCardlessException $exception) {
                Log::info('Direct Debit portal return: not finished yet, the webhook will', ['company_id' => $company->id, 'error' => $exception->getMessage()]);
            }
        }

        return $this->accounts->for($company)->hasUsableMandate();
    }
}
