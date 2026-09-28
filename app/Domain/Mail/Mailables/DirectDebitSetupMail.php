<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Mail\Data\DirectDebitSetupData;
use App\Domain\Mail\Support\MailFormat;
use Illuminate\Mail\Mailables\Content;

final class DirectDebitSetupMail extends BrandedMailable
{
    public function __construct(public DirectDebitSetupData $data) {}

    public static function templateKey(): string
    {
        return 'direct-debit-setup';
    }

    public static function templateLabel(): string
    {
        return 'Direct Debit setup';
    }

    public static function templateDescription(): string
    {
        return 'Sent by staff (tenant Billing tab) to a business paying by Direct Debit: a link to the GoCardless page that sets up the mandate.';
    }

    public static function sample(): static
    {
        return new self(new DirectDebitSetupData(
            businessName: 'Khan Mini Mart',
            ownerName: 'Aisha Khan',
            setupUrl: config('app.url').'/direct-debit/example',
            setupFee: '418.80',
            setupInstalments: 3,
            recurring: '60.00',
            per: 'per month',
            tillCount: 2,
        ));
    }

    public function subjectLine(): string
    {
        return 'Set up your Direct Debit for Switch & Save';
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
        $facts = [];

        if ($this->data->recurring !== null) {
            $facts['Your subscription'] = MailFormat::money($this->data->recurring).' '.$this->data->per.' ('.MailFormat::count($this->data->tillCount, 'till').', VAT included)';
        }

        if ($this->data->setupFee !== null) {
            $facts['Setup fee'] = MailFormat::money($this->data->setupFee).($this->data->setupInstalments > 1 ? " in {$this->data->setupInstalments} monthly payments" : ', once').' (VAT included)';
        }

        return new Content(markdown: 'mail.direct-debit-setup', with: [
            'firstName' => MailFormat::firstName($this->data->ownerName),
            'facts' => $facts,
            'linkDays' => (int) config('billing.direct_debit.setup_link_days', 14),
        ]);
    }
}
