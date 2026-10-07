<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Mail\Data\DirectDebitCancelledData;
use App\Domain\Mail\Enums\EmailCategory;
use App\Domain\Mail\Support\MailFormat;
use Illuminate\Mail\Mailables\Content;

final class DirectDebitCancelledMail extends BrandedMailable
{
    public function __construct(public DirectDebitCancelledData $data) {}

    public static function templateKey(): string
    {
        return 'direct-debit-cancelled';
    }

    public static function templateLabel(): string
    {
        return 'Direct Debit cancelled';
    }

    public static function templateDescription(): string
    {
        return 'Sent to the owners (and a copy to staff) when a Direct Debit mandate is cancelled, fails or expires.';
    }

    public static function sample(): static
    {
        return new self(new DirectDebitCancelledData(
            businessName: 'Khan Mini Mart',
            ownerName: 'Aisha Khan',
            status: 'Cancelled',
            graceUntil: now()->addDays(3)->startOfDay(),
            setupUrl: config('app.url').'/direct-debit/example',
        ));
    }

    public function emailCategory(): EmailCategory
    {
        return EmailCategory::Reminders;
    }

    public function subjectLine(): string
    {
        return 'Your Direct Debit to Switch & Save has stopped';
    }

    public function companyId(): ?string
    {
        return $this->data->companyId;
    }

    public function logMeta(): array
    {
        return ['business' => $this->data->businessName, 'status' => $this->data->status];
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.direct-debit-cancelled', with: [
            'firstName' => MailFormat::firstName($this->data->ownerName),
            'graceUntil' => MailFormat::date($this->data->graceUntil),
        ]);
    }
}
