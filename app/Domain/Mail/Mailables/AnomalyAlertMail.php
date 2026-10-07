<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Mail\Data\AnomalyAlertData;
use App\Domain\Mail\Enums\EmailCategory;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\Shared\Country\LocalText;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Headers;

/**
 * Unusual activity, high severity (module 6.6): sent once per finding to owners and managers (and, for shop-level
 * findings, accountants) who chose "Unusual activity" straight away. Lower severities go in the 07:00 digest.
 */
final class AnomalyAlertMail extends BrandedMailable
{
    public function __construct(public AnomalyAlertData $data) {}

    public static function templateKey(): string
    {
        return 'anomaly-alert';
    }

    public static function templateLabel(): string
    {
        return 'Unusual activity alert';
    }

    public static function templateDescription(): string
    {
        return 'Sent straight away to users who chose it when the anomaly checks find something far from normal: a shop with no sales for hours, repeated cash shortfalls, a spike in voids or refunds.';
    }

    public static function sample(): static
    {
        $portal = (string) config('sspos.portal_url');

        return new self(new AnomalyAlertData(
            businessName: 'Khan Mini Mart',
            recipientName: 'Aisha Khan',
            title: LocalText::places('Leeds: no sales since 12:00 today'),
            summary: LocalText::places('No sales have reached the portal from Leeds for 3 hours (12:00–15:00). On the last 8 Thursdays those hours took a usual 42 sales. A till may be down or not syncing, or the shop may be shut.'),
            shopName: LocalText::places('Leeds'),
            kindLabel: 'No sales for several hours',
            facts: ['Hours without a sale' => '3 (12:00–15:00)', 'Sales in those hours' => '0 (usual 42)'],
            url: $portal.'/app/anomalies',
            unsubscribeUrl: $portal.'/app/settings/notifications',
            settingsUrl: $portal.'/app/settings/notifications',
        ));
    }

    public function emailCategory(): EmailCategory
    {
        return EmailCategory::OwnerAlerts;
    }

    public function subjectLine(): string
    {
        return 'Unusual activity: '.$this->data->title;
    }

    public function companyId(): ?string
    {
        return $this->data->companyId;
    }

    public function logMeta(): array
    {
        return ['business' => $this->data->businessName, 'alert' => 'unusualActivity', 'shop' => $this->data->shopName];
    }

    public function headers(): Headers
    {
        return new Headers(text: ['List-Unsubscribe' => '<'.$this->data->unsubscribeUrl.'>']);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.anomaly-alert', with: [
            'firstName' => MailFormat::firstName($this->data->recipientName),
            'facts' => ['Business' => $this->data->businessName, 'Shop' => $this->data->shopName, 'What' => $this->data->kindLabel, ...$this->data->facts],
        ]);
    }
}
