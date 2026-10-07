<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Mail\Data\LicenceRenewedData;
use App\Domain\Mail\Data\RenewedTillData;
use App\Domain\Mail\Enums\EmailCategory;
use App\Domain\Mail\Support\MailFormat;
use Illuminate\Mail\Mailables\Content;

/**
 * Sent after a payment renews licences (cash billing, module 1.8).
 */
final class LicenceRenewedMail extends BrandedMailable
{
    public function __construct(public LicenceRenewedData $data) {}

    public static function templateKey(): string
    {
        return 'licence-renewed';
    }

    public static function templateLabel(): string
    {
        return 'Licences renewed';
    }

    public static function templateDescription(): string
    {
        return 'Sent after a payment is recorded: which tills were renewed and their new expiry date.';
    }

    public static function sample(): static
    {
        $expiry = now()->addMonth()->startOfDay();

        return new self(new LicenceRenewedData(
            businessName: 'Khan Mini Mart',
            ownerName: 'Aisha Khan',
            tills: [
                new RenewedTillData('High Street', 'Till 1', $expiry),
                new RenewedTillData('High Street', 'Till 2', $expiry),
                new RenewedTillData('Station Road', 'Till 1', $expiry),
            ],
            newExpiry: $expiry,
            amountPaid: '75.00',
            reference: 'INV-0042',
        ));
    }

    public function emailCategory(): EmailCategory
    {
        return EmailCategory::Reminders;
    }

    public function subjectLine(): string
    {
        $what = count($this->data->tills) === 1 ? 'licence is' : 'licences are';

        return "Your Switch & Save {$what} renewed until ".MailFormat::date($this->data->newExpiry);
    }

    public function companyId(): ?string
    {
        return $this->data->companyId;
    }

    public function logMeta(): array
    {
        return [
            'business' => $this->data->businessName,
            'tills' => count($this->data->tills),
            'new_expiry' => $this->data->newExpiry->toDateString(),
            'reference' => $this->data->reference,
        ];
    }

    public function content(): Content
    {
        $facts = ['Business' => $this->data->businessName, 'Renewed until' => MailFormat::date($this->data->newExpiry)];

        if ($this->data->amountPaid !== null) {
            $facts['Amount paid'] = MailFormat::money($this->data->amountPaid);
        }

        if ($this->data->reference !== null) {
            $facts['Reference'] = $this->data->reference;
        }

        return new Content(markdown: 'mail.licence-renewed', with: [
            'firstName' => MailFormat::firstName($this->data->ownerName),
            'facts' => $facts,
            'tills' => array_map(fn (RenewedTillData $till) => [
                'branch' => $till->branchName,
                'till' => $till->tillName,
                'expires' => MailFormat::date($till->expiresAt),
            ], $this->data->tills),
            'tillCount' => MailFormat::count(count($this->data->tills), 'till'),
            'portalUrl' => config('sspos.portal_url').'/login',
        ]);
    }
}
