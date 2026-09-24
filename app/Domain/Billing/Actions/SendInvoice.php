<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingMailer;
use App\Domain\Shared\Actions\RecordAudit;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Emails an issued invoice with its PDF (InvoiceMail) to the company's billing emails, else its active owners.
 * Used by IssueInvoice and "Send again". Returns how many emails were queued.
 */
class SendInvoice
{
    public function __construct(
        private readonly BillingMailer $mailer,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Invoice $invoice, bool $resent = true): int
    {
        if ($invoice->isDraft() || $invoice->status === InvoiceStatus::Void) {
            throw ValidationException::withMessages(['status' => $invoice->isDraft()
                ? 'Issue the invoice before sending it.'
                : "{$invoice->number} is void, so it cannot be sent."]);
        }

        $invoice->loadMissing(['company', 'lines']);
        $sent = $this->mailer->invoice($invoice, $resent);

        if ($sent === 0) {
            throw ValidationException::withMessages(['status' => 'There is no billing email or active owner to send it to. Add a billing email in the billing settings.']);
        }

        $invoice->sent_count++;
        $invoice->last_sent_at = CarbonImmutable::now();
        $invoice->save();

        $this->audit->handle('invoice.sent', $invoice, null, null, [
            'number' => $invoice->number,
            'recipients' => $sent,
            'resent' => $resent,
        ]);

        return $sent;
    }
}
