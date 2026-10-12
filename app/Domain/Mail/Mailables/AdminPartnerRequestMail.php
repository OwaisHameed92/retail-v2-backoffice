<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Mail\Data\PartnerRequestAlertData;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\Shared\Country\LocalText;
use App\Domain\Shared\Country\MoneyFormat;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;

/**
 * Staff alert (owner 2026-10-12): a partner sent a business for review on Admin → Partner requests, sent a rejected
 * one again, or cancelled one already approved. Goes to the same addresses as new trial requests and till requests
 * (`sspos.lead_alert_emails`, else `sspos.staff_email`), never held.
 *
 * Usage: Mail::queue(new AdminPartnerRequestMail($data));
 */
final class AdminPartnerRequestMail extends BrandedMailable
{
    public function __construct(public PartnerRequestAlertData $data) {}

    public static function templateKey(): string
    {
        return 'admin-partner-request';
    }

    public static function templateLabel(): string
    {
        return 'Partner request (staff)';
    }

    public static function templateDescription(): string
    {
        return 'Sent to the Switch & Save team when a partner (Zivaro) sends a business for review, sends a rejected one again, or cancels one already approved.';
    }

    public static function audience(): string
    {
        return 'staff';
    }

    public static function sample(): static
    {
        return new self(new PartnerRequestAlertData(
            event: PartnerRequestAlertData::NEW,
            requestId: '01K5T0Q8C4000000000000P001',
            businessName: 'Istanbul Market',
            partnerName: 'Zivaro',
            sellerName: 'Ali Khan',
            sellerEmail: LocalText::domains('ali@example.co.uk'),
            shops: 1,
            tills: 2,
            plan: 'setup-only',
            setupFeePerTill: '750.00',
            receivedAt: now()->subMinutes(2),
        ));
    }

    public function subjectLine(): string
    {
        return match ($this->data->event) {
            PartnerRequestAlertData::SENT_AGAIN => 'Partner request sent again: '.$this->data->businessName,
            PartnerRequestAlertData::PARTNER_CANCELLED => 'Partner cancelled an approved business: '.$this->data->businessName,
            default => 'New partner request: '.$this->data->businessName,
        };
    }

    public function logMeta(): array
    {
        return ['business' => $this->data->businessName, 'partner' => $this->data->partnerName, 'event' => $this->data->event];
    }

    protected function defaultRecipients(): array
    {
        $list = array_values(array_filter(array_map('trim', (array) config('sspos.partner_alert_emails', []))));
        $list = $list !== [] ? $list : array_values(array_filter(array_map('trim', (array) config('sspos.lead_alert_emails', []))));

        if ($list === []) {
            return [new Address((string) config('sspos.staff_email'), 'Switch & Save team')];
        }

        return array_map(fn (string $email) => new Address($email), $list);
    }

    public function content(): Content
    {
        $facts = [
            'Business' => $this->data->businessName,
            'Partner' => $this->data->partnerName,
            'Sold by' => $this->data->sellerName.' ('.$this->data->sellerEmail.')',
            'Shops and tills' => $this->data->shops.' '.($this->data->shops === 1 ? 'shop' : 'shops').', '.$this->data->tills.' '.($this->data->tills === 1 ? 'till' : 'tills'),
            'Plan' => $this->data->plan,
            'Setup fee per till' => $this->data->setupFeePerTill !== null ? MoneyFormat::format($this->data->setupFeePerTill) : 'The plan’s',
            'Received' => MailFormat::dateTime($this->data->receivedAt),
        ];

        if ($this->data->reason !== null) {
            $facts['Partner’s reason'] = $this->data->reason;
        }

        return new Content(markdown: 'mail.admin-partner-request', with: [
            'facts' => $facts,
            'requestUrl' => rtrim((string) config('app.url'), '/').'/admin/partner-requests/'.$this->data->requestId,
        ]);
    }
}
