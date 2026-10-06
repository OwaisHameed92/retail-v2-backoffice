<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Mail\Data\TrialEndedData;
use App\Domain\Mail\Support\MailFormat;
use Illuminate\Mail\Mailables\Content;

final class TrialEndedMail extends BrandedMailable
{
    public function __construct(public TrialEndedData $data) {}

    public static function templateKey(): string
    {
        return 'trial-ended';
    }

    public static function templateLabel(): string
    {
        return 'Trial ended';
    }

    public static function templateDescription(): string
    {
        return 'Sent when the free trial ends without payment. Tills stop trading until the account is paid.';
    }

    public static function sample(): static
    {
        return new self(new TrialEndedData(
            businessName: 'Khan Mini Mart',
            ownerName: 'Aisha Khan',
            endedAt: now()->startOfDay()->addHours(9),
            tillCount: 3,
            priceSummary: MailFormat::money('25').' per till per month',
        ));
    }

    public function subjectLine(): string
    {
        return 'Your Switch & Save free trial has ended';
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
        return new Content(markdown: 'mail.trial-ended', with: [
            'firstName' => MailFormat::firstName($this->data->ownerName),
            'endedOn' => MailFormat::date($this->data->endedAt),
            'tillCount' => MailFormat::count($this->data->tillCount, 'till'),
            'portalUrl' => config('sspos.portal_url').'/login',
        ]);
    }
}
