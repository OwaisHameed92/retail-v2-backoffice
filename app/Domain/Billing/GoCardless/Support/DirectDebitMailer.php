<?php

namespace App\Domain\Billing\GoCardless\Support;

use App\Domain\Billing\Data\InvoiceDocument;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Licensing\Support\LicenceMailer;
use App\Domain\Mail\Data\AccountSuspendedData;
use App\Domain\Mail\Data\DirectDebitCancelledData;
use App\Domain\Mail\Data\DirectDebitFailedData;
use App\Domain\Mail\Data\DirectDebitSetupData;
use App\Domain\Mail\Mailables\AccountSuspendedMail;
use App\Domain\Mail\Mailables\DirectDebitCancelledMail;
use App\Domain\Mail\Mailables\DirectDebitFailedMail;
use App\Domain\Mail\Mailables\DirectDebitSetupMail;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * Queues the Direct Debit emails (module 1.12) to the company's active owners: the setup link, a failed or
 * charged back payment, and a stopped mandate (with a copy to staff). Returns how many were queued.
 */
final class DirectDebitMailer
{
    public function __construct(private readonly LicenceMailer $licenceMailer) {}

    public function setup(Company $company, BillingAccount $account): int
    {
        $owners = $this->licenceMailer->owners($company);
        $amount = SubscriptionAmount::for($company, $account);
        $setup = $account->setup_fee_invoiced_at === null ? SetupFee::totals($company, $account) : null;
        $url = SetupLink::for($company);

        foreach ($owners as $owner) {
            Mail::to($owner->email)->queue(new DirectDebitSetupMail(new DirectDebitSetupData(
                businessName: $company->name,
                ownerName: $owner->name,
                setupUrl: $url,
                setupFee: $setup !== null && ! Money::isZero($setup['gross']) ? $setup['gross'] : null,
                setupInstalments: max(1, $account->setup_fee_instalments),
                recurring: Money::isZero($amount['gross']) ? null : $amount['gross'],
                per: $amount['cycle']->per(),
                tillCount: $amount['tills'],
                companyId: $company->id,
            )));
        }

        return $owners->count();
    }

    public function failed(Company $company, GoCardlessPayment $payment, bool $reminder = false): int
    {
        $owners = $this->licenceMailer->owners($company);

        foreach ($owners as $owner) {
            Mail::to($owner->email)->queue(new DirectDebitFailedMail(new DirectDebitFailedData(
                businessName: $company->name,
                ownerName: $owner->name,
                amount: $payment->amount,
                invoiceNumber: $payment->invoice?->number,
                chargeDate: $payment->charge_date,
                reason: $payment->failure_reason,
                chargedBack: $payment->status->value === 'chargedBack',
                reminder: $reminder,
                bankDetails: InvoiceDocument::bankLines(),
                companyId: $company->id,
            )));
        }

        return $owners->count();
    }

    public function mandateLost(Company $company, BillingAccount $account, CarbonImmutable $graceUntil): int
    {
        $owners = $this->licenceMailer->owners($company);
        $url = SetupLink::for($company);
        $status = $account->gc_mandate_status?->label() ?? 'Cancelled';

        foreach ($owners as $owner) {
            Mail::to($owner->email)->queue(new DirectDebitCancelledMail(new DirectDebitCancelledData($company->name, $owner->name, $status, $graceUntil, $url, $company->id)));
        }

        // Staff copy (without the customer's link), so accounts can call the customer.
        Mail::to((string) config('sspos.staff_email'))->queue(new DirectDebitCancelledMail(new DirectDebitCancelledData($company->name, 'Switch & Save team', $status, $graceUntil, null, $company->id)));

        return $owners->count() + 1;
    }

    /** AccountSuspendedMail for "no Direct Debit after the trial", with the setup link as the way out. */
    public function suspendedWithoutMandate(Company $company, string $reason, CarbonImmutable $at): int
    {
        $owners = $this->licenceMailer->owners($company);
        $fix = 'Set up your Direct Debit from Billing in your portal ('.config('sspos.portal_url').'/app/billing) or at '.SetupLink::for($company).' and your tills unlock straight away. Or reply to this email to pay another way.';

        foreach ($owners as $owner) {
            Mail::to($owner->email)->queue(new AccountSuspendedMail(new AccountSuspendedData(
                businessName: $company->name,
                ownerName: $owner->name,
                reason: $reason.'.',
                suspendedAt: $at,
                howToFix: $fix,
                companyId: $company->id,
            )));
        }

        return $owners->count();
    }
}
