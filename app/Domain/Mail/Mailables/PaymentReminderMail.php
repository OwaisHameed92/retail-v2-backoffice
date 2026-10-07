<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Mail\Data\PaymentReminderData;
use App\Domain\Mail\Enums\EmailCategory;
use App\Domain\Mail\Support\MailFormat;
use Illuminate\Mail\Mailables\Content;

/**
 * Pakistan plan P5 (manual collection): reminders for an invoice paid by hand, sent by billing:run a few days before
 * the due date, on it, and a few days after it while it is unpaid. Only listed (and sent) on an instance whose fees
 * are collected by hand; a Direct Debit (GB) instance never has it.
 */
final class PaymentReminderMail extends BrandedMailable
{
    public function __construct(public PaymentReminderData $data) {}

    public static function templateKey(): string
    {
        return 'payment-reminder';
    }

    public static function templateLabel(): string
    {
        return 'Payment reminder';
    }

    public static function templateDescription(): string
    {
        return 'Sent for an invoice paid by hand: a few days before it is due, on the due date, and a few days after it while it is unpaid.';
    }

    public static function sample(): static
    {
        return new self(new PaymentReminderData(
            businessName: 'Khan Mini Mart',
            recipientName: 'Aisha Khan',
            invoiceNumber: 'INV-000042',
            kind: PaymentReminderData::DUE_SOON,
            dueDate: now()->addDays(3)->startOfDay(),
            balance: '7500.00',
            howToPay: 'Pay by bank transfer, JazzCash, Easypaisa or cash, quoting INV-000042 as the reference.',
            payLines: ['Meezan Bank', 'Account title Switch & Save', 'IBAN PK36MEZN0000000000000000', 'JazzCash 0300 1234567', 'Easypaisa 0345 1234567'],
        ));
    }

    public function emailCategory(): EmailCategory
    {
        return EmailCategory::Reminders;
    }

    public function subjectLine(): string
    {
        $number = $this->data->invoiceNumber;

        return match ($this->data->kind) {
            PaymentReminderData::DUE_TODAY => "Invoice {$number} is due today",
            PaymentReminderData::OVERDUE => "Invoice {$number} is overdue",
            default => "Reminder: invoice {$number} is due on ".MailFormat::date($this->data->dueDate),
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
            'reminder' => $this->data->kind,
            'balance' => $this->data->balance,
            'due' => $this->data->dueDate->toDateString(),
        ];
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.payment-reminder', with: [
            'firstName' => MailFormat::firstName($this->data->recipientName ?? ''),
            'dueOn' => MailFormat::date($this->data->dueDate),
            'locksOn' => $this->data->locksOn !== null ? MailFormat::date($this->data->locksOn) : null,
            'amountDue' => MailFormat::money($this->data->balance),
            'facts' => [
                'Invoice' => $this->data->invoiceNumber,
                'Amount due' => MailFormat::money($this->data->balance),
                'Due date' => MailFormat::date($this->data->dueDate),
            ],
            'portalUrl' => config('sspos.portal_url').'/app/billing',
        ]);
    }
}
