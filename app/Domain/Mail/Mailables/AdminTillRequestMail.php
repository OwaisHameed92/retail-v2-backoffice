<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Mail\Data\TillRequestData;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\Shared\Country\LocalText;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;

/**
 * Staff alert: a business asked for more tills or another shop from its portal (module 4.7, SendShopRequest). Goes
 * to the same addresses as new trial requests (`sspos.lead_alert_emails`, else `sspos.staff_email`).
 *
 * Usage: Mail::queue(new AdminTillRequestMail($data));
 */
final class AdminTillRequestMail extends BrandedMailable
{
    public function __construct(public TillRequestData $data) {}

    public static function templateKey(): string
    {
        return 'admin-till-request';
    }

    public static function templateLabel(): string
    {
        return 'More tills requested (staff)';
    }

    public static function templateDescription(): string
    {
        return 'Sent to the Switch & Save team when a business asks for more tills or another shop from its portal.';
    }

    public static function audience(): string
    {
        return 'staff';
    }

    public static function sample(): static
    {
        return new self(new TillRequestData(
            businessName: 'Patel News & Booze',
            companyId: '01K5T0Q8C4000000000000C001',
            kind: 'More tills',
            what: '1 more till for Leeds (LDS)',
            requestedBy: 'Imran Patel',
            email: LocalText::domains('imran@patelnews.co.uk'),
            phone: LocalText::phone('07700 900123'),
            receivedAt: now()->subMinutes(3),
            message: 'We are putting a second counter in for the lottery. Can we have it by Friday?',
        ));
    }

    public function subjectLine(): string
    {
        return $this->data->kind.' requested: '.$this->data->businessName;
    }

    public function companyId(): string
    {
        return $this->data->companyId;
    }

    public function logMeta(): array
    {
        return ['business' => $this->data->businessName, 'kind' => $this->data->kind];
    }

    protected function defaultRecipients(): array
    {
        $list = array_values(array_filter(array_map('trim', (array) config('sspos.lead_alert_emails', []))));

        if ($list === []) {
            return [new Address((string) config('sspos.staff_email'), 'Switch & Save team')];
        }

        return array_map(fn (string $email) => new Address($email), $list);
    }

    public function content(): Content
    {
        $facts = [
            'Business' => $this->data->businessName,
            'Asked for' => $this->data->what,
            'Asked by' => $this->data->requestedBy,
            'Email' => $this->data->email,
            'Phone' => $this->data->phone ?? 'Not given',
            'Received' => MailFormat::dateTime($this->data->receivedAt),
        ];

        if ($this->data->count > 1) {
            $facts['Times asked'] = (string) $this->data->count;
        }

        return new Content(markdown: 'mail.admin-till-request', with: [
            'facts' => $facts,
            'tenantUrl' => rtrim((string) config('app.url'), '/').'/admin/tenants/'.$this->data->companyId,
        ]);
    }
}
