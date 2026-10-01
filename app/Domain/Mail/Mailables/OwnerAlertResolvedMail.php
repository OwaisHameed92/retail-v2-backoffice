<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Mail\Data\OwnerAlertData;
use App\Domain\Mail\Support\MailFormat;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Headers;

/**
 * Follows an {@see OwnerAlertMail} when the problem clears (module 7.8): the till is back, or sync works again.
 */
final class OwnerAlertResolvedMail extends BrandedMailable
{
    public function __construct(public OwnerAlertData $data) {}

    public static function templateKey(): string
    {
        return 'owner-alert-resolved';
    }

    public static function templateLabel(): string
    {
        return 'Owner alert: resolved';
    }

    public static function templateDescription(): string
    {
        return 'Sent to everyone who had the urgent alert email once the till is back online or the shop\'s sync works again.';
    }

    public static function sample(): static
    {
        return new self(new OwnerAlertData(
            businessName: 'Khan Mini Mart',
            recipientName: 'Aisha Khan',
            type: 'tillOffline',
            problem: 'tillOffline',
            shopName: 'Leeds',
            tillName: 'Till 2',
            summary: null,
            since: CarbonImmutable::now()->subHours(5),
            url: config('sspos.portal_url').'/app/shops',
            unsubscribeUrl: config('sspos.portal_url').'/app/settings/notifications',
            settingsUrl: config('sspos.portal_url').'/app/settings/notifications',
            resolvedAt: CarbonImmutable::now(),
        ));
    }

    public function subjectLine(): string
    {
        return 'Resolved: '.$this->data->headline();
    }

    public function companyId(): ?string
    {
        return $this->data->companyId;
    }

    public function logMeta(): array
    {
        return ['business' => $this->data->businessName, 'alert' => $this->data->problem, 'shop' => $this->data->shopName, 'resolved' => true];
    }

    public function headers(): Headers
    {
        return new Headers(text: ['List-Unsubscribe' => '<'.$this->data->unsubscribeUrl.'>']);
    }

    public function content(): Content
    {
        $resolved = $this->data->resolvedAt ?? CarbonImmutable::now();
        $minutes = (int) $this->data->since->diffInMinutes($resolved, true);

        return new Content(markdown: 'mail.owner-alert-resolved', with: [
            'firstName' => MailFormat::firstName($this->data->recipientName),
            'facts' => array_filter([
                'Business' => $this->data->businessName,
                'Shop' => $this->data->shopName,
                'Till' => $this->data->tillName,
                'Started' => MailFormat::dateTime($this->data->since),
                'Cleared' => MailFormat::dateTime($resolved),
                'Lasted' => $minutes >= 120 ? intdiv($minutes, 60).' hours' : $minutes.' minutes',
            ], fn ($value) => $value !== null && $value !== ''),
        ]);
    }
}
