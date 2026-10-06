<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Mail\Data\NewLeadData;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\Shared\Country\LocalText;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;

/**
 * Staff alert for a new trial request (CreateLead, module 1.6: admin "Add lead" and the public trial form 1.10).
 * Goes to every address in config('sspos.lead_alert_emails'), or config('sspos.staff_email') when that is empty.
 *
 * Usage: Mail::queue(new AdminNewLeadMail($data));
 */
final class AdminNewLeadMail extends BrandedMailable
{
    public function __construct(public NewLeadData $data) {}

    public static function templateKey(): string
    {
        return 'admin-new-lead';
    }

    public static function templateLabel(): string
    {
        return 'New trial request (staff)';
    }

    public static function templateDescription(): string
    {
        return 'Sent to the Switch & Save team when someone asks for a free trial.';
    }

    public static function audience(): string
    {
        return 'staff';
    }

    public static function sample(): static
    {
        return new self(new NewLeadData(
            contactName: 'Imran Patel',
            businessName: 'Patel News & Booze',
            email: LocalText::domains('imran@patelnews.co.uk'),
            phone: LocalText::phone('07700 900123'),
            shops: 2,
            tills: 3,
            receivedAt: now()->subMinutes(4),
            message: 'We are moving from our old EPOS next month and would like to try it in the Leeds shop first.',
        ));
    }

    public function subjectLine(): string
    {
        return 'New trial request: '.$this->data->businessName;
    }

    public function logMeta(): array
    {
        return ['business' => $this->data->businessName, 'shops' => $this->data->shops, 'tills' => $this->data->tills];
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
            'Contact' => $this->data->contactName,
            'Email' => $this->data->email,
            'Phone' => $this->data->phone ?? 'Not given',
            'Shops' => (string) $this->data->shops,
            'Tills' => (string) $this->data->tills,
            'Received' => MailFormat::dateTime($this->data->receivedAt),
        ];

        if ($this->data->addedBy !== null) {
            $facts['Added by'] = $this->data->addedBy;
        }

        $leadsUrl = rtrim((string) config('app.url'), '/').'/admin/leads';

        return new Content(markdown: 'mail.admin-new-lead', with: [
            'facts' => $facts,
            'leadsUrl' => $leadsUrl,
            'leadUrl' => $this->data->leadId !== null ? $leadsUrl.'/'.$this->data->leadId : $leadsUrl,
        ]);
    }
}
