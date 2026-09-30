<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Mail\Data\TillRequestData;
use App\Domain\Mail\Support\MailFormat;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;

/**
 * Staff alert: a business asked from My subscription to cancel or to change its Direct Debit bank account (module
 * 4.10, SendBillingRequest). Goes to the staff address (`sspos.staff_email`), where billing is handled.
 *
 * Usage: Mail::queue(new AdminSubscriptionRequestMail($data));
 */
final class AdminSubscriptionRequestMail extends BrandedMailable
{
    public function __construct(public TillRequestData $data) {}

    public static function templateKey(): string
    {
        return 'admin-subscription-request';
    }

    public static function templateLabel(): string
    {
        return 'Subscription request (staff)';
    }

    public static function templateDescription(): string
    {
        return 'Sent to the Switch & Save team when a business asks to cancel or to change its bank account from its portal.';
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
            kind: 'Cancellation',
            what: 'to cancel the subscription',
            requestedBy: 'Imran Patel',
            email: 'imran@patelnews.co.uk',
            phone: '07700 900123',
            receivedAt: now()->subMinutes(3),
            message: 'We are selling the shop at the end of next month.',
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
        return [new Address((string) config('sspos.staff_email'), 'Switch & Save team')];
    }

    public function content(): Content
    {
        $facts = [
            'Business' => $this->data->businessName,
            'Asked' => ucfirst($this->data->what),
            'Asked by' => $this->data->requestedBy,
            'Email' => $this->data->email,
            'Phone' => $this->data->phone ?? 'Not given',
            'Received' => MailFormat::dateTime($this->data->receivedAt),
        ];

        if ($this->data->count > 1) {
            $facts['Times asked'] = (string) $this->data->count;
        }

        return new Content(markdown: 'mail.admin-subscription-request', with: [
            'facts' => $facts,
            'tenantUrl' => rtrim((string) config('app.url'), '/').'/admin/tenants/'.$this->data->companyId,
        ]);
    }
}
