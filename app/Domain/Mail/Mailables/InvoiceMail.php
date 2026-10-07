<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Billing\Support\ManualCollection;
use App\Domain\Mail\Contracts\RendersAttachment;
use App\Domain\Mail\Data\InvoiceMailData;
use App\Domain\Mail\Enums\EmailCategory;
use App\Domain\Mail\Support\MailFormat;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;

/**
 * An invoice with its PDF attached (cash billing, module 1.8): sent when an invoice is issued and again from
 * "Send again". Wording follows the status (due, overdue, paid). The PDF is rendered in the queue worker from
 * the invoice id, so the queued payload holds no document.
 */
final class InvoiceMail extends BrandedMailable
{
    public function __construct(public InvoiceMailData $data) {}

    public static function templateKey(): string
    {
        return 'invoice';
    }

    public static function templateLabel(): string
    {
        return 'Invoice';
    }

    public static function templateDescription(): string
    {
        return 'Sent when an invoice is issued, or sent again by staff: the amount, due date and how to pay, with the PDF attached.';
    }

    public static function sample(): static
    {
        return new self(new InvoiceMailData(
            businessName: 'Khan Mini Mart',
            recipientName: 'Aisha Khan',
            invoiceNumber: 'INV-000042',
            periodLabel: '1 Oct – 31 Oct 2026',
            issueDate: now()->startOfDay(),
            dueDate: now()->addDays(7)->startOfDay(),
            total: '90.00',
            balance: '90.00',
            status: 'issued',
            tillCount: 3,
            bankDetails: ManualCollection::active()
                ? ['Meezan Bank', 'Account title Switch & Save', 'IBAN PK36MEZN0000000000000000', 'JazzCash 0300 1234567', 'Easypaisa 0345 1234567']
                : ['Switch & Save Ltd', 'Sort code 12-34-56', 'Account 12345678'],
            // Pakistan plan P5 (manual collection): paid by hand; GB keeps the UK "How to pay" text.
            howToPay: ManualCollection::active() ? ManualCollection::howToPay('INV-000042') : null,
        ));
    }

    public function emailCategory(): EmailCategory
    {
        return EmailCategory::Invoices;
    }

    public function subjectLine(): string
    {
        $number = $this->data->invoiceNumber;

        return match ($this->data->status) {
            'overdue' => "Invoice {$number} is overdue",
            'paid' => "Invoice {$number} (paid)",
            default => "Invoice {$number} from Switch & Save, due ".MailFormat::date($this->data->dueDate),
        };
    }

    public function companyId(): ?string
    {
        return $this->data->companyId;
    }

    public function logMeta(): array
    {
        return [
            'business' => $this->data->businessName,
            'invoice' => $this->data->invoiceNumber,
            'total' => $this->data->total,
            'balance' => $this->data->balance,
            'due' => $this->data->dueDate->toDateString(),
            'resent' => $this->data->resent,
        ];
    }

    public function content(): Content
    {
        $facts = [
            'Invoice' => $this->data->invoiceNumber,
            'Period' => $this->data->periodLabel,
            'Tills' => (string) $this->data->tillCount,
            'Total' => MailFormat::money($this->data->total),
        ];

        if ($this->data->status !== 'paid') {
            $facts['Amount due'] = MailFormat::money($this->data->balance);
            $facts['Due date'] = MailFormat::date($this->data->dueDate);
        }

        if ($this->data->directDebitOn !== null && $this->data->status !== 'paid') {
            unset($facts['Due date']);
            $facts['Direct Debit on'] = MailFormat::date($this->data->directDebitOn);
        }

        return new Content(markdown: 'mail.invoice', with: [
            'firstName' => MailFormat::firstName($this->data->recipientName ?? ''),
            'facts' => $facts,
            'overdue' => $this->data->status === 'overdue',
            'paid' => $this->data->status === 'paid',
            'dueOn' => MailFormat::date($this->data->dueDate),
            'directDebitOn' => $this->data->directDebitOn !== null ? MailFormat::date($this->data->directDebitOn) : null,
            'amountDue' => MailFormat::money($this->data->balance),
            'portalUrl' => config('sspos.portal_url').'/login',
        ]);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $renderer = $this->data->pdfRenderer;
        $key = $this->data->pdfKey;

        if ($renderer === null || $key === null) {
            return [];
        }

        $pdf = app($renderer);

        if (! $pdf instanceof RendersAttachment) {
            return [];
        }

        $bytes = $pdf->renderAttachment($key);

        return $bytes === null ? [] : [
            Attachment::fromData(fn () => $bytes, $this->data->invoiceNumber.'.pdf')->withMime('application/pdf'),
        ];
    }
}
