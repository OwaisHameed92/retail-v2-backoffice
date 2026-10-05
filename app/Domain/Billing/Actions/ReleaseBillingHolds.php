<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\BillingMailer;
use App\Domain\Billing\Support\BillingPeriod;
use App\Domain\Billing\Support\MandateDeadline;
use App\Domain\Licensing\Actions\RenewCompanyLicences;
use App\Domain\Tenancy\Actions\ActivateCompany;
use App\Domain\Tenancy\Actions\MarkCompanyOverdue;
use App\Domain\Tenancy\Actions\UnsuspendCompany;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Lifts what billing put on a company once the reason is gone (owner rules 2026-10-05: reactivated automatically):
 *
 * - the billing suspension (UnsuspendCompany + the "account active again" email) once no invoice is still overdue
 *   past the suspension grace and the Direct Debit deadline is not missed (a "no Direct Debit" suspension stays
 *   until a mandate exists, whatever is paid by hand);
 * - an overdue company back to active once nothing at all is overdue (with younger overdue invoices left it
 *   stays, or becomes, overdue).
 *
 * A suspension staff made for another reason is never lifted here: only the one whose time matches
 * `billing_suspended_at`.
 */
class ReleaseBillingHolds
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly UnsuspendCompany $unsuspendCompany,
        private readonly ActivateCompany $activateCompany,
        private readonly MarkCompanyOverdue $markCompanyOverdue,
        private readonly BillingMailer $mailer,
    ) {}

    /** Returns true when a billing suspension was lifted. */
    public function handle(Company $company): bool
    {
        $overdue = Invoice::withoutCompanyScope()->where('company_id', $company->id)->where('status', InvoiceStatus::Overdue->value);
        $days = max(0, (int) config('billing.suspend_after_days', 14));
        $now = CarbonImmutable::now();
        $blocking = $overdue->clone()->where('due_date', '<', BillingDates::today($now)->subDays($days)->format('Y-m-d'))
            ->where(fn ($q) => $q->whereNull('reopened_at')->orWhere('reopened_at', '<', $now->subDays($days)))->exists();

        $company->refresh();
        $account = $this->accounts->for($company);

        if ($blocking || MandateDeadline::missed($company, $account, $now)) {
            return false;
        }

        $lifted = false;
        $stillOverdue = $overdue->clone()->exists();

        if ($account->billing_suspended_at !== null) {
            $ours = $company->status === CompanyStatus::Suspended
                && $company->suspended_at !== null
                && $company->suspended_at->getTimestamp() === $account->billing_suspended_at->getTimestamp();

            if ($ours) {
                $this->unsuspendCompany->handle($company);
                $lifted = true;
            }

            $account->billing_suspended_at = null;
            $account->suspension_invoice_id = null;
            $account->save();
        }

        $company->refresh();

        if ($stillOverdue) {
            $this->markCompanyOverdue->handle($company, 'Invoice still overdue');
        } elseif ($company->status === CompanyStatus::Overdue) {
            try {
                $this->activateCompany->handle($company);
            } catch (ValidationException) {
                // Changed in between.
            }
        }

        if ($lifted) {
            $licences = RenewCompanyLicences::renewable($company)->get();
            $this->mailer->reactivated($company, $licences->count(), BillingPeriod::anchor($company, $licences));
        }

        return $lifted;
    }
}
