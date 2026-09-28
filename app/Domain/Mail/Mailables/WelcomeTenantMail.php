<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Mail\Data\TillKeyData;
use App\Domain\Mail\Data\WelcomeTenantData;
use App\Domain\Mail\Support\MailFormat;
use Illuminate\Mail\Mailables\Content;

/**
 * Sent once when a tenant is set up (trial approved, module 1.6). The only place licence keys are ever shown.
 */
final class WelcomeTenantMail extends BrandedMailable
{
    public function __construct(public WelcomeTenantData $data) {}

    public static function templateKey(): string
    {
        return 'welcome-tenant';
    }

    public static function templateLabel(): string
    {
        return 'Welcome and licence keys';
    }

    public static function templateDescription(): string
    {
        return 'Sent to the owner when their account is set up. Lists every till with its licence key, shown only in this email.';
    }

    public static function sample(): static
    {
        return new self(new WelcomeTenantData(
            businessName: 'Khan Mini Mart',
            ownerName: 'Aisha Khan',
            ownerEmail: 'aisha@khanminimart.co.uk',
            loginUrl: config('sspos.portal_url').'/login',
            tills: [
                new TillKeyData('High Street', 'Till 1', 'SSP-7K2Q-9DMF-3XRA-P8T5'),
                new TillKeyData('High Street', 'Till 2', 'SSP-4HWC-J6ZB-81ME-QV5H'),
                new TillKeyData('Station Road', 'Till 1', 'SSP-2NRX-T7KP-5G0A-DYF6'),
            ],
            trialDays: 7,
            billingUrl: config('sspos.portal_url').'/app/billing',
            directDebitDays: 3,
        ));
    }

    public function subjectLine(): string
    {
        return 'Welcome to Switch & Save – your licence keys';
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
            'branches' => $this->data->branchCount(),
            'trial_days' => $this->data->trialDays,
        ];
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.welcome-tenant', with: [
            'firstName' => MailFormat::firstName($this->data->ownerName),
            'tillCount' => MailFormat::count(count($this->data->tills), 'till'),
            'downloadUrl' => (string) config('sspos.epos_download_url'),
            'tills' => $this->data->tills,
        ]);
    }
}
