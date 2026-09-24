<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Data\InvoiceDocument;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\Actor;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\BillingMailer;
use App\Domain\Billing\Support\DocumentNumbers;
use App\Domain\Billing\Support\InvoiceBalance;
use App\Domain\Billing\Support\Vat;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues a draft: takes the next invoice number (gap-free, in this transaction), dates it today (London) with
 * the company's payment terms, freezes who it is billed to, uses any unallocated credit, and emails it with the
 * PDF (InvoiceMail, queued after commit). An invoice with nothing to pay is settled straight away.
 */
class IssueInvoice
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly DocumentNumbers $numbers,
        private readonly ApplyCredit $applyCredit,
        private readonly SettleInvoice $settleInvoice,
        private readonly SendInvoice $sendInvoice,
        private readonly BillingMailer $mailer,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Invoice $invoice, bool $send = true): Invoice
    {
        return DB::transaction(function () use ($invoice, $send) {
            $now = CarbonImmutable::now();
            /** @var Company $company */
            $company = $invoice->company()->firstOrFail();
            $account = $this->accounts->lock($company);
            $invoice = Invoice::withoutCompanyScope()->lockForUpdate()->findOrFail($invoice->id);

            if (! $invoice->isDraft()) {
                throw ValidationException::withMessages(['status' => "{$invoice->number} is already issued."]);
            }

            if ($company->trashed() || $company->isCancelled()) {
                throw ValidationException::withMessages(['status' => "{$company->name} is cancelled. Reactivate it before issuing invoices."]);
            }

            if ($invoice->lines()->count() === 0) {
                throw ValidationException::withMessages(['status' => 'Add at least one line before issuing the invoice.']);
            }

            $billTo = InvoiceDocument::billTo($invoice);
            [$sequence, $number] = $this->numbers->next(DocumentNumbers::INVOICE);
            $today = BillingDates::today($now);

            $invoice->forceFill([
                'number' => $number,
                'sequence' => $sequence,
                'status' => InvoiceStatus::Issued,
                'issue_date' => $today,
                'due_date' => $today->addDays(max(0, $account->payment_terms_days)),
                'seller_vat_number' => Vat::number(),
                'bill_to_name' => $billTo['name'],
                'bill_to_address' => implode("\n", $billTo['address']) ?: null,
                'bill_to_emails' => array_keys($this->mailer->invoiceRecipients($company)),
                'issued_at' => $now,
                'issued_by_admin_id' => Actor::adminId(),
            ])->save();

            $this->audit->handle('invoice.issued', $invoice, ['status' => InvoiceStatus::Draft->value], ['status' => InvoiceStatus::Issued->value], [
                'number' => $number,
                'total' => $invoice->total,
                'due_date' => $invoice->due_date?->format('Y-m-d'),
            ]);

            if (config('billing.apply_credit_on_issue', true)) {
                $this->applyCredit->applyLocked($company, $invoice, $now);
            }

            if (InvoiceBalance::refresh($invoice) && $invoice->isOpen()) {
                $this->settleInvoice->handle($invoice, $now);
            }

            if ($send) {
                try {
                    $this->sendInvoice->handle($invoice->refresh(), resent: false);
                } catch (ValidationException) {
                    // Nobody to email yet: the invoice is still issued; staff can "Send again" later.
                }
            }

            return $invoice->refresh();
        });
    }
}
