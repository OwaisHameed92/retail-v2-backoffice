<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Data\NewInvoice;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingPeriod;
use App\Domain\Licensing\Actions\RenewCompanyLicences;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * billing:run step: creates the next invoice for every company whose tills run out (BillingPeriod::anchor)
 * within `billing.generate.days_before` days and that has no invoice (draft or issued, not void) for that next
 * period yet. Drafts for staff to review, or issued and emailed when `billing.generate.auto_issue` is on.
 * Idempotent: the overlap check stops a second invoice for the same period. Companies paying by Direct Debit
 * with a live GoCardless subscription are skipped (their invoices come with each GoCardless payment).
 */
class GenerateDueInvoices
{
    public function __construct(
        private readonly GenerateInvoice $generateInvoice,
        private readonly BillingAccounts $accounts,
    ) {}

    /**
     * @return list<string> ids of the invoices created
     */
    public function handle(CarbonImmutable $now): array
    {
        $days = max(0, (int) config('billing.generate.days_before', 7));
        $horizon = $now->addDays($days);
        $created = [];

        // Also tills that ran out in the last few days (a missed run): their next period starts today.
        $since = $now->subDays(max(1, $days));

        $companyIds = Licence::withoutCompanyScope()->live()
            ->where('ends_at', '>', $since)->where('ends_at', '<=', $horizon)
            ->distinct()->pluck('company_id');

        foreach ($companyIds as $companyId) {
            $company = Company::query()->find($companyId);

            if ($company === null || $company->status === CompanyStatus::Cancelled) {
                continue;
            }

            // Direct Debit (module 1.12): GoCardless creates the payment and the invoice comes with it. Without a
            // live subscription (no mandate yet, or it was cancelled) the company is invoiced by hand as before.
            $account = $this->accounts->for($company);

            if ($account->isDirectDebit() && $account->hasLiveSubscription()) {
                continue;
            }

            $licences = RenewCompanyLicences::renewable($company)->get();
            $anchor = BillingPeriod::anchor($company, $licences);

            if ($anchor === null || $anchor->lessThanOrEqualTo($since) || $anchor->greaterThan($horizon)) {
                continue;
            }

            $input = new NewInvoice(issue: (bool) config('billing.generate.auto_issue', false), auto: true);

            try {
                $plan = $this->generateInvoice->plan($company, $input, $now);

                if ($plan->overlapsNumber !== null || $plan->lines === []) {
                    continue;
                }

                $created[] = $this->generateInvoice->handle($company, $input)->id;
            } catch (ValidationException $exception) {
                Log::warning('billing:run could not create an invoice', [
                    'company_id' => $company->id,
                    'errors' => $exception->errors(),
                ]);
            }
        }

        return $created;
    }
}
