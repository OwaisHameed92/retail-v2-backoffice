<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Mail\Data\DirectDebitFailedData;
use App\Domain\Mail\Support\MailFormat;
use Illuminate\Mail\Mailables\Content;

final class DirectDebitFailedMail extends BrandedMailable
{
    public function __construct(public DirectDebitFailedData $data) {}

    public static function templateKey(): string
    {
        return 'direct-debit-failed';
    }

    public static function templateLabel(): string
    {
        return 'Direct Debit failed';
    }

    public static function templateDescription(): string
    {
        return 'Sent when a Direct Debit payment fails or is charged back, and once more a few days later while the invoice is unpaid.';
    }

    public static function sample(): static
    {
        return new self(new DirectDebitFailedData(
            businessName: 'Khan Mini Mart',
            ownerName: 'Aisha Khan',
            amount: '60.00',
            invoiceNumber: 'INV-000123',
            chargeDate: now()->startOfDay(),
            reason: 'The bank account had insufficient funds.',
            bankDetails: ['Switch & Save Ltd', 'Sort code 00-00-00', 'Account 00000000'],
        ));
    }

    public function subjectLine(): string
    {
        return match (true) {
            $this->data->reminder => 'Reminder: your Switch & Save payment is still unpaid',
            $this->data->chargedBack => 'Your Direct Debit payment was reversed',
            default => 'Your Direct Debit payment failed',
        };
    }

    public function companyId(): ?string
    {
        return $this->data->companyId;
    }

    public function logMeta(): array
    {
        return ['business' => $this->data->businessName, 'invoice' => $this->data->invoiceNumber, 'amount' => $this->data->amount, 'reminder' => $this->data->reminder];
    }

    public function content(): Content
    {
        $facts = array_filter([
            'Amount' => MailFormat::money($this->data->amount),
            'Invoice' => $this->data->invoiceNumber,
            'Collection date' => $this->data->chargeDate !== null ? MailFormat::date($this->data->chargeDate) : null,
            'Reason' => $this->data->reason,
        ]);

        return new Content(markdown: 'mail.direct-debit-failed', with: [
            'firstName' => MailFormat::firstName($this->data->ownerName),
            'facts' => $facts,
            'amount' => MailFormat::money($this->data->amount),
            'suspendAfter' => (int) config('billing.suspend_after_days', 14),
        ]);
    }
}
