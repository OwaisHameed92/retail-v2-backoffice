<?php

namespace App\Domain\Billing\GoCardless\Actions;

use App\Domain\Billing\GoCardless\Contracts\GoCardlessClient;
use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Demo\Support\DemoBusinesses;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The owner pressed "Set up Direct Debit" on the portal Billing page (module 1.13): a fresh GoCardless hosted page
 * that returns to the portal. Refused when the business does not pay by Direct Debit or already has a mandate.
 */
class StartOwnerMandateSetup
{
    public function __construct(
        private readonly GoCardlessClient $client,
        private readonly BillingAccounts $accounts,
        private readonly StartMandateSetup $startMandateSetup,
    ) {}

    /**
     * @return string the GoCardless page to send the owner to
     *
     * @throws ValidationException
     */
    public function handle(Company $company, string $returnUrl, string $exitUrl): string
    {
        if (DemoBusinesses::isDemo($company)) {
            throw ValidationException::withMessages(['status' => 'This is a demo business: the Direct Debit page never opens for it (nothing is sent to GoCardless).']);
        }

        $account = $this->accounts->for($company);

        if ($account->hasUsableMandate()) {
            throw ValidationException::withMessages(['status' => 'Your Direct Debit is already set up.']);
        }

        if (! $account->isDirectDebit() || $company->isCancelled()) {
            throw ValidationException::withMessages(['status' => 'Your account is not paid by Direct Debit. Contact Switch & Save to change how you pay.']);
        }

        if (! $this->client->enabled()) {
            throw ValidationException::withMessages(['status' => 'Direct Debit setup is not available right now. Please try again later or contact Switch & Save.']);
        }

        try {
            return $this->startMandateSetup->handle($company, $returnUrl, $exitUrl);
        } catch (GoCardlessException $exception) {
            Log::warning('Direct Debit setup page could not be opened from the portal', ['company_id' => $company->id, 'error' => $exception->getMessage()]);

            throw ValidationException::withMessages(['status' => 'We could not open the Direct Debit page. Please try again in a minute.']);
        }
    }
}
