<?php

namespace App\Http\Controllers\DirectDebit;

use App\Domain\Billing\GoCardless\Actions\CompleteMandateSetup;
use App\Domain\Billing\GoCardless\Actions\StartMandateSetup;
use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Tenancy\Models\Company;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer's side of the Direct Debit setup (module 1.12). Both routes are signed links (`signed` middleware),
 * so only someone holding our email can use them; no login is needed.
 *
 * - `GET /direct-debit/{company}/setup`: opens a GoCardless setup page (a fresh one when the last expired).
 * - `GET /direct-debit/{company}/done`: GoCardless sends the customer back here; we try to finish at once (the
 *   `billing_requests.fulfilled` webhook finishes it anyway) and say thank you.
 */
class DirectDebitSetupController extends Controller
{
    public function setup(Company $company, StartMandateSetup $start, BillingAccounts $accounts): RedirectResponse|Response
    {
        $account = $accounts->for($company);

        if ($account->hasUsableMandate()) {
            return $this->page($company, 'ready');
        }

        if ($company->isCancelled() || ! $account->isDirectDebit()) {
            return $this->page($company, 'unavailable');
        }

        try {
            return redirect()->away($start->handle($company));
        } catch (GoCardlessException $exception) {
            Log::warning('Direct Debit setup page could not be opened', ['company_id' => $company->id, 'error' => $exception->getMessage()]);

            return $this->page($company, 'unavailable');
        }
    }

    public function done(Company $company, CompleteMandateSetup $complete, BillingAccounts $accounts): Response
    {
        $account = $accounts->for($company);

        if (! $account->hasUsableMandate() && $account->gc_billing_request_id !== null) {
            try {
                $complete->handle($company, $account->gc_billing_request_id);
            } catch (GoCardlessException $exception) {
                Log::info('Direct Debit return: not finished yet, the webhook will', ['company_id' => $company->id, 'error' => $exception->getMessage()]);
            }
        }

        return $this->page($company, $accounts->for($company)->hasUsableMandate() ? 'ready' : 'pending');
    }

    /**
     * @param  'ready'|'pending'|'unavailable'  $state
     */
    private function page(Company $company, string $state): Response
    {
        return Inertia::render('direct-debit', [
            'state' => $state,
            'businessName' => $company->name,
            'supportEmail' => (string) config('sspos.support_email'),
            'supportPhone' => (string) config('sspos.support_phone') ?: null,
            'portalUrl' => (string) config('sspos.portal_url').'/login',
        ]);
    }
}
