<?php

namespace App\Domain\Billing\GoCardless\Actions;

use App\Domain\Billing\GoCardless\Contracts\GoCardlessClient;
use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\Billing\GoCardless\Support\SetupLink;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Licensing\Support\LicenceMailer;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The GoCardless page where the customer sets up the Direct Debit: reuses the company's open billing request
 * while its page has more than an hour left, else starts a new one (prefilled with the owner and business). Our
 * signed email link calls this, so the link keeps working after a GoCardless page expires.
 *
 * Module 1.13: the owner's "Set up Direct Debit" button in the portal passes its own return and exit pages and
 * always gets a fresh page (the cached one would send them back to the email flow's page).
 */
class StartMandateSetup
{
    public function __construct(
        private readonly GoCardlessClient $client,
        private readonly BillingAccounts $accounts,
        private readonly LicenceMailer $licenceMailer,
    ) {}

    /** @throws GoCardlessException */
    public function handle(Company $company, ?string $redirectUri = null, ?string $exitUri = null): string
    {
        $account = $this->accounts->for($company);
        $now = CarbonImmutable::now();
        $portal = $redirectUri !== null;

        if (! $portal && $account->gc_setup_url !== null && $account->gc_setup_url_expires_at?->greaterThan($now->addHour())) {
            return $account->gc_setup_url;
        }

        $owner = $this->licenceMailer->owners($company)->first();
        $name = trim((string) ($owner->name ?? $company->owner_name ?? ''));

        $flow = $this->client->startMandateSetup(
            redirectUri: $redirectUri ?? SetupLink::done($company),
            exitUri: $exitUri ?? (string) config('sspos.portal_url'),
            prefill: array_filter([
                'email' => $owner->email ?? $company->email,
                'given_name' => Str::before($name, ' ') ?: null,
                'family_name' => str_contains($name, ' ') ? Str::after($name, ' ') : null,
                'company_name' => $company->legal_name ?: $company->name,
            ], fn (mixed $value) => is_string($value) && $value !== ''),
            metadata: ['company_id' => $company->id],
        );

        DB::transaction(function () use ($company, $flow, $now, $portal) {
            $account = $this->accounts->lock($company);
            $account->gc_billing_request_id = $flow->billingRequestId;
            $account->gc_setup_url = $portal ? null : $flow->url;
            $account->gc_setup_url_expires_at = $portal ? null : ($flow->expiresAt ?? $now->addDays(7));
            $account->save();
        });

        return $flow->url;
    }
}
