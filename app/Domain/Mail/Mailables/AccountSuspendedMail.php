<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Mail\Data\AccountSuspendedData;
use App\Domain\Mail\Enums\EmailCategory;
use App\Domain\Mail\Support\MailFormat;
use Illuminate\Mail\Mailables\Content;

final class AccountSuspendedMail extends BrandedMailable
{
    public function __construct(public AccountSuspendedData $data) {}

    public static function templateKey(): string
    {
        return 'account-suspended';
    }

    public static function templateLabel(): string
    {
        return 'Account suspended';
    }

    public static function templateDescription(): string
    {
        return 'Sent when we suspend an account: the reason, what it means for the tills and how to fix it.';
    }

    public static function sample(): static
    {
        return new self(new AccountSuspendedData(
            businessName: 'Khan Mini Mart',
            ownerName: 'Aisha Khan',
            reason: 'Invoice INV-0042 is 14 days overdue.',
            suspendedAt: now()->startOfDay()->addHours(9),
            amountDue: '75.00',
        ));
    }

    public function emailCategory(): EmailCategory
    {
        return EmailCategory::Reminders;
    }

    public function subjectLine(): string
    {
        return 'Your Switch & Save account is suspended';
    }

    public function companyId(): ?string
    {
        return $this->data->companyId;
    }

    public function logMeta(): array
    {
        return ['business' => $this->data->businessName, 'reason' => $this->data->reason];
    }

    public function content(): Content
    {
        $supportPhone = (string) config('sspos.support_phone');

        return new Content(markdown: 'mail.account-suspended', with: [
            'firstName' => MailFormat::firstName($this->data->ownerName),
            'suspendedOn' => MailFormat::date($this->data->suspendedAt),
            'amountDue' => $this->data->amountDue === null ? null : MailFormat::money($this->data->amountDue),
            'howToFix' => $this->data->howToFix,
            'supportEmail' => (string) config('sspos.support_email'),
            'supportPhone' => $supportPhone === '' ? null : $supportPhone,
        ]);
    }
}
