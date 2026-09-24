<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingMailer;
use App\Domain\Billing\Support\BillingPeriod;
use App\Domain\Licensing\Actions\RenewCompanyLicences;
use App\Domain\Tenancy\Actions\ActivateCompany;
use App\Domain\Tenancy\Actions\UnsuspendCompany;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Validation\ValidationException;

/**
 * Once a company has nothing overdue: lift the suspension billing:run put on it (UnsuspendCompany + the
 * "account active again" email) and turn an overdue company back to active. A suspension staff made for another
 * reason is never lifted here: only the one whose time matches `billing_suspended_at`.
 */
class ReleaseBillingHolds
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly UnsuspendCompany $unsuspendCompany,
        private readonly ActivateCompany $activateCompany,
        private readonly BillingMailer $mailer,
    ) {}

    /** Returns true when a billing suspension was lifted. */
    public function handle(Company $company): bool
    {
        $overdue = Invoice::withoutCompanyScope()->where('company_id', $company->id)->where('status', InvoiceStatus::Overdue->value)->exists();

        if ($overdue) {
            return false;
        }

        $company->refresh();
        $account = $this->accounts->for($company);
        $lifted = false;

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

        if ($company->status === CompanyStatus::Overdue) {
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
