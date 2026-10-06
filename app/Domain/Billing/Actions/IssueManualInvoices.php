<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Data\NewInvoice;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\BillingPeriod;
use App\Domain\Billing\Support\ManualCollection;
use App\Domain\Billing\Support\SetupFeeState;
use App\Domain\Licensing\Actions\RenewCompanyLicences;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * billing:run step on a manual-collection instance (Pakistan plan P5), in place of GenerateDueInvoices: every
 * monthly or yearly period is an invoice, issued and emailed `billing.generate.days_before` (7) days before the
 * company's tills run out (BillingPeriod::anchor), due `billing.manual.due_days` (7) after issue, so normally on
 * the period start. Paying it renews the tills to the period end (SettleInvoice), as in the UK.
 *
 * The recurring fee starts once the setup fee (or its first instalment) is paid, as the UK Direct Debit does, and at
 * once for monthly-only plans; setup-only plans have nothing to invoice. Idempotent: the overlap check stops a second
 * invoice for the same period.
 */
class IssueManualInvoices
{
    public function __construct(
        private readonly GenerateInvoice $generateInvoice,
        private readonly BillingAccounts $accounts,
    ) {}

    /**
     * @return list<string> ids of the invoices issued
     */
    public function handle(CarbonImmutable $now, ?string $companyId = null): array
    {
        $days = max(0, (int) config('billing.generate.days_before', 7));
        $horizon = $now->addDays($days);
        // Also tills that ran out in the last few days (a missed run): their next period starts today.
        $since = $now->subDays(max(1, $days));
        $issued = [];

        $companyIds = Licence::withoutCompanyScope()->live()
            ->where('ends_at', '>', $since)->where('ends_at', '<=', $horizon)
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->distinct()->pluck('company_id');

        foreach ($companyIds as $id) {
            $company = Company::query()->find($id);

            if ($company !== null && ($invoice = $this->issueNext($company, $now, $since)) !== null) {
                $issued[] = $invoice->id;
            }
        }

        return $issued;
    }

    /**
     * The company's next period invoice, issued now when its tills run out within `days_before` days, or already ran
     * out (`$since` null: however long ago, e.g. the setup fee was paid after the trial). Null when nothing is due.
     */
    public function issueNext(Company $company, CarbonImmutable $now, ?CarbonImmutable $since = null): ?Invoice
    {
        if (! ManualCollection::active() || $company->status === CompanyStatus::Cancelled || ! $this->recurringStarted($company)) {
            return null;
        }

        $licences = RenewCompanyLicences::renewable($company)->get();
        $anchor = BillingPeriod::anchor($company, $licences);
        $horizon = $now->addDays(max(0, (int) config('billing.generate.days_before', 7)));

        if ($anchor === null || $anchor->greaterThan($horizon) || ($since !== null && $anchor->lessThanOrEqualTo($since))) {
            return null;
        }

        $input = new NewInvoice(issue: true, auto: true, dueDate: BillingDates::today($now)->addDays(ManualCollection::dueDays()));

        try {
            $plan = $this->generateInvoice->plan($company, $input, $now);

            if ($plan->overlapsNumber !== null || $plan->lines === []) {
                return null;
            }

            return $this->generateInvoice->handle($company, $input);
        } catch (ValidationException $exception) {
            Log::warning('billing:run could not issue an invoice', ['company_id' => $company->id, 'errors' => $exception->errors()]);

            return null;
        }
    }

    /** Something recurs and, on a plan with a setup fee, the setup fee (or its first instalment) is paid. */
    private function recurringStarted(Company $company): bool
    {
        $type = ApplySetupFeeTerms::planType($company);

        if ($type === PlanBillingType::SetupOnly) {
            return false;
        }

        return SetupFeeState::for($company, $this->accounts->for($company))->isStarted();
    }
}
