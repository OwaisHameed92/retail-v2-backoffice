<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Mail\Data\LicenceKeyData;
use App\Domain\Mail\Data\TillKeyData;
use App\Domain\Mail\Support\MailFormat;
use Illuminate\Mail\Mailables\Content;

/**
 * Sent when staff email a till's new licence key to the owner from the "Licence key created" dialog (module 1.3):
 * a till added later, or a key replaced. Like the welcome email, the only place the key is shown.
 */
final class LicenceKeyMail extends BrandedMailable
{
    public function __construct(public LicenceKeyData $data) {}

    public static function templateKey(): string
    {
        return 'licence-key';
    }

    public static function templateLabel(): string
    {
        return 'New licence key';
    }

    public static function templateDescription(): string
    {
        return 'Sent when staff email a new or replaced licence key to the owner. Shows the key, only in this email.';
    }

    public static function sample(): static
    {
        return new self(new LicenceKeyData(
            businessName: 'Khan Mini Mart',
            ownerName: 'Aisha Khan',
            tills: [new TillKeyData('Station Road', 'Till 2', 'SSP-4HWC-J6ZB-81ME-QV5H')],
        ));
    }

    public function subjectLine(): string
    {
        $count = count($this->data->tills);

        if ($count === 1) {
            return 'Your licence key for '.$this->data->tills[0]->tillName;
        }

        return "Your {$count} new licence keys";
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
            'replaced' => $this->data->replacesOldKey,
        ];
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.licence-key', with: [
            'firstName' => MailFormat::firstName($this->data->ownerName),
            'single' => count($this->data->tills) === 1,
            'tillCount' => MailFormat::count(count($this->data->tills), 'till'),
            'downloadUrl' => (string) config('sspos.epos_download_url'),
            'tills' => $this->data->tills,
        ]);
    }
}
