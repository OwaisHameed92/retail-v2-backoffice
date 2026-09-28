<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Data\InvoiceDocument;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\LicenceMailer;
use App\Domain\Mail\Data\AccountReactivatedData;
use App\Domain\Mail\Data\AccountSuspendedData;
use App\Domain\Mail\Data\InvoiceMailData;
use App\Domain\Mail\Data\LicenceRenewedData;
use App\Domain\Mail\Data\RenewedTillData;
use App\Domain\Mail\Data\TrialEndedData;
use App\Domain\Mail\Data\TrialReminderData;
use App\Domain\Mail\Mailables\AccountReactivatedMail;
use App\Domain\Mail\Mailables\AccountSuspendedMail;
use App\Domain\Mail\Mailables\InvoiceMail;
use App\Domain\Mail\Mailables\LicenceRenewedMail;
use App\Domain\Mail\Mailables\TrialEndedMail;
use App\Domain\Mail\Mailables\TrialReminderMail;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Queues the module 1.7 emails billing sends (branded, encrypted, after commit). Invoices go to the company's
 * billing emails, else its active owners; account emails (renewed, suspended, reactivated, trial) go to the
 * active owners. Every method returns how many emails were queued.
 */
final class BillingMailer
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly LicenceMailer $licenceMailer,
    ) {}

    public function invoice(Invoice $invoice, bool $resent = false): int
    {
        /** @var Company $company */
        $company = $invoice->company;
        $recipients = $this->invoiceRecipients($company);
        $tills = $invoice->lines->whereNotNull('licence_id')->count();

        foreach ($recipients as $email => $name) {
            Mail::to($email)->queue(new InvoiceMail(new InvoiceMailData(
                businessName: $company->name,
                recipientName: $name,
                invoiceNumber: (string) $invoice->number,
                periodLabel: BillingDates::range($invoice->period_start, $invoice->period_end),
                issueDate: $invoice->issue_date ?? BillingDates::today(),
                dueDate: $invoice->due_date ?? BillingDates::today(),
                total: $invoice->total,
                balance: $invoice->balance,
                status: $invoice->status->value,
                tillCount: $tills > 0 ? $tills : $invoice->lines->count(),
                resent: $resent,
                bankDetails: InvoiceDocument::bankLines(),
                pdfRenderer: InvoicePdf::class,
                pdfKey: $invoice->id,
                companyId: $company->id,
                directDebitOn: $this->directDebitDate($invoice),
            )));
        }

        return count($recipients);
    }

    /** The day GoCardless collects this invoice, while that payment is still on its way (module 1.12). */
    private function directDebitDate(Invoice $invoice): ?CarbonImmutable
    {
        return GoCardlessPayment::withoutCompanyScope()->where('invoice_id', $invoice->id)
            ->whereIn('status', PaymentStatus::pendingValues())->orderByDesc('charge_date')->first()?->charge_date;
    }

    /**
     * Billing emails (no names), else the active owners by name.
     *
     * @return array<string, string|null> email => name
     */
    public function invoiceRecipients(Company $company): array
    {
        $emails = $this->accounts->for($company)->emails();

        if ($emails !== []) {
            return array_fill_keys(array_values(array_unique($emails)), null);
        }

        return $this->owners($company)->mapWithKeys(fn (User $owner) => [$owner->email => $owner->name])->all();
    }

    /**
     * "Your licences are renewed" after an invoice is paid, with the amount and the invoice number.
     *
     * @param  list<Licence>  $licences
     */
    public function renewed(Invoice $invoice, array $licences, CarbonImmutable $expiresAt): int
    {
        /** @var Company $company */
        $company = $invoice->company;
        $owners = $this->owners($company);
        $tills = array_map(fn (Licence $licence) => new RenewedTillData(
            $licence->branch->name ?? 'Branch',
            $licence->register->name ?? 'Till',
            $licence->expires_at ?? $expiresAt,
        ), $licences);

        foreach ($owners as $owner) {
            Mail::to($owner->email)->queue(new LicenceRenewedMail(new LicenceRenewedData(
                businessName: $company->name,
                ownerName: $owner->name,
                tills: $tills,
                newExpiry: $expiresAt,
                amountPaid: $invoice->amount_paid,
                reference: $invoice->number,
                companyId: $company->id,
            )));
        }

        return $owners->count();
    }

    public function suspended(Company $company, string $reason, string $amountDue, CarbonImmutable $at): int
    {
        $owners = $this->owners($company);

        foreach ($owners as $owner) {
            Mail::to($owner->email)->queue(new AccountSuspendedMail(new AccountSuspendedData(
                businessName: $company->name,
                ownerName: $owner->name,
                reason: $reason.'.',
                suspendedAt: $at,
                amountDue: $amountDue,
                companyId: $company->id,
            )));
        }

        return $owners->count();
    }

    public function reactivated(Company $company, int $tillCount, ?CarbonImmutable $activeUntil): int
    {
        $owners = $this->owners($company);

        foreach ($owners as $owner) {
            Mail::to($owner->email)->queue(new AccountReactivatedMail(new AccountReactivatedData(
                businessName: $company->name,
                ownerName: $owner->name,
                tillCount: $tillCount,
                activeUntil: $activeUntil,
                companyId: $company->id,
            )));
        }

        return $owners->count();
    }

    public function trialReminder(Company $company, CarbonImmutable $trialEndsAt, int $daysLeft, int $tillCount, ?string $priceSummary, ?string $directDebitUrl = null): int
    {
        $owners = $this->owners($company);

        foreach ($owners as $owner) {
            Mail::to($owner->email)->queue(new TrialReminderMail(new TrialReminderData(
                businessName: $company->name,
                ownerName: $owner->name,
                daysLeft: $daysLeft,
                trialEndsAt: $trialEndsAt,
                tillCount: $tillCount,
                priceSummary: $priceSummary,
                companyId: $company->id,
                directDebitUrl: $directDebitUrl,
            )));
        }

        return $owners->count();
    }

    public function trialEnded(Company $company, CarbonImmutable $endedAt, int $tillCount, ?string $priceSummary, ?string $directDebitUrl = null): int
    {
        $owners = $this->owners($company);

        foreach ($owners as $owner) {
            Mail::to($owner->email)->queue(new TrialEndedMail(new TrialEndedData(
                businessName: $company->name,
                ownerName: $owner->name,
                endedAt: $endedAt,
                tillCount: $tillCount,
                priceSummary: $priceSummary,
                companyId: $company->id,
                directDebitUrl: $directDebitUrl,
            )));
        }

        return $owners->count();
    }

    /**
     * @return Collection<int, User>
     */
    private function owners(Company $company): Collection
    {
        return $this->licenceMailer->owners($company);
    }

    /** Number of tills on an invoice (lines with a licence). */
    public static function tillCount(Invoice $invoice): int
    {
        return $invoice->lines->filter(fn (InvoiceLine $line) => $line->licence_id !== null)->count();
    }
}
