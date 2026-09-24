<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Mail\Data\AccountReactivatedData;
use App\Domain\Mail\Support\MailFormat;
use Illuminate\Mail\Mailables\Content;

final class AccountReactivatedMail extends BrandedMailable
{
    public function __construct(public AccountReactivatedData $data) {}

    public static function templateKey(): string
    {
        return 'account-reactivated';
    }

    public static function templateLabel(): string
    {
        return 'Account reactivated';
    }

    public static function templateDescription(): string
    {
        return 'Sent when a suspended account is active again and the tills can trade.';
    }

    public static function sample(): static
    {
        return new self(new AccountReactivatedData(
            businessName: 'Khan Mini Mart',
            ownerName: 'Aisha Khan',
            tillCount: 3,
            activeUntil: now()->addMonth()->startOfDay(),
        ));
    }

    public function subjectLine(): string
    {
        return 'Your Switch & Save account is active again';
    }

    public function companyId(): ?string
    {
        return $this->data->companyId;
    }

    public function logMeta(): array
    {
        return ['business' => $this->data->businessName, 'tills' => $this->data->tillCount];
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.account-reactivated', with: [
            'firstName' => MailFormat::firstName($this->data->ownerName),
            'tillCount' => MailFormat::count($this->data->tillCount, 'till'),
            'activeUntil' => $this->data->activeUntil === null ? null : MailFormat::date($this->data->activeUntil),
            'portalUrl' => config('sspos.portal_url').'/login',
        ]);
    }
}
