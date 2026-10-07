<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Mail\Data\OwnerAlertData;
use App\Domain\Mail\Enums\EmailCategory;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\Shared\Country\LocalText;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Headers;

/**
 * Urgent owner alert (module 7.8): a till offline during opening hours or a shop's sync failing / stalled. Sent at
 * most once per problem and user within 6 hours; {@see OwnerAlertResolvedMail} follows when it clears.
 */
final class OwnerAlertMail extends BrandedMailable
{
    public function __construct(public OwnerAlertData $data) {}

    public static function templateKey(): string
    {
        return 'owner-alert';
    }

    public static function templateLabel(): string
    {
        return 'Owner alert: till offline or sync failing';
    }

    public static function templateDescription(): string
    {
        return 'Sent straight away to owners and managers who chose it when a till is offline while the shop is open, or a shop\'s sync fails.';
    }

    public static function sample(): static
    {
        return new self(new OwnerAlertData(
            businessName: 'Khan Mini Mart',
            recipientName: 'Aisha Khan',
            type: 'tillOffline',
            problem: 'tillOffline',
            shopName: LocalText::places('Leeds'),
            tillName: 'Till 2',
            summary: 'Last heard from 9 Nov 2026, 08:12.',
            since: CarbonImmutable::now()->subHours(4),
            url: config('sspos.portal_url').'/app/shops',
            unsubscribeUrl: config('sspos.portal_url').'/app/settings/notifications',
            settingsUrl: config('sspos.portal_url').'/app/settings/notifications',
        ));
    }

    public function emailCategory(): EmailCategory
    {
        return EmailCategory::OwnerAlerts;
    }

    public function subjectLine(): string
    {
        return $this->data->headline();
    }

    public function companyId(): ?string
    {
        return $this->data->companyId;
    }

    public function logMeta(): array
    {
        return ['business' => $this->data->businessName, 'alert' => $this->data->problem, 'shop' => $this->data->shopName];
    }

    public function headers(): Headers
    {
        return new Headers(text: ['List-Unsubscribe' => '<'.$this->data->unsubscribeUrl.'>']);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.owner-alert', with: [
            'firstName' => MailFormat::firstName($this->data->recipientName),
            'facts' => array_filter([
                'Business' => $this->data->businessName,
                'Shop' => $this->data->shopName,
                'Till' => $this->data->tillName,
                'Since' => MailFormat::dateTime($this->data->since),
                'Details' => $this->data->summary,
            ], fn ($value) => $value !== null && $value !== ''),
        ]);
    }
}
