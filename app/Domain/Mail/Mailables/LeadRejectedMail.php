<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Mail\Data\LeadRejectedData;
use App\Domain\Mail\Support\MailFormat;
use Illuminate\Mail\Mailables\Content;

/**
 * Optional reply when staff turn down a trial request (RejectLead with "email the prospect"). Short and polite;
 * the internal reason is never included.
 *
 * Usage: Mail::to($lead->email)->queue(new LeadRejectedMail($data));
 */
final class LeadRejectedMail extends BrandedMailable
{
    public function __construct(public LeadRejectedData $data) {}

    public static function templateKey(): string
    {
        return 'lead-rejected';
    }

    public static function templateLabel(): string
    {
        return 'Trial request declined';
    }

    public static function templateDescription(): string
    {
        return 'Optional reply when we cannot offer a free trial to someone who asked for one.';
    }

    public static function sample(): static
    {
        return new self(new LeadRejectedData(
            contactName: 'Imran Patel',
            businessName: 'Patel News & Booze',
        ));
    }

    public function subjectLine(): string
    {
        return 'Your Switch & Save trial request';
    }

    public function logMeta(): array
    {
        return ['business' => $this->data->businessName];
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.lead-rejected', with: [
            'firstName' => MailFormat::firstName($this->data->contactName),
        ]);
    }
}
