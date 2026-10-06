<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Mail\Data\PortalInvitationData;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\Shared\Country\LocalText;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Support\Carbon;

/**
 * "You're invited to {business} on Switch & Save" (module 4.1): a signed link, valid 7 days, to join the portal.
 */
final class PortalInvitationMail extends BrandedMailable
{
    public function __construct(public PortalInvitationData $data) {}

    public static function templateKey(): string
    {
        return 'portal-invitation';
    }

    public static function templateLabel(): string
    {
        return 'Portal invitation';
    }

    public static function templateDescription(): string
    {
        return 'An owner invites someone to their business portal. The link is valid for 7 days; resending sends a new one.';
    }

    public static function sample(): static
    {
        return new self(new PortalInvitationData(
            name: 'Bilal Ahmed',
            email: LocalText::domains('bilal@khanminimart.co.uk'),
            businessName: 'Khan Mini Mart',
            roleLabel: 'Manager',
            branchName: 'Leeds Road',
            inviterName: 'Aisha Khan',
            url: config('sspos.portal_url').'/app/invitations/01J00000000000000000000000/sample-token',
            expiresAt: Carbon::now()->addDays(7),
        ));
    }

    public function subjectLine(): string
    {
        return "You're invited to {$this->data->businessName} on Switch & Save";
    }

    public function companyId(): ?string
    {
        return $this->data->companyId;
    }

    public function logMeta(): array
    {
        return ['role' => $this->data->roleLabel];
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.portal-invitation', with: [
            'firstName' => MailFormat::firstName($this->data->name),
            'expiresOn' => MailFormat::date($this->data->expiresAt),
        ]);
    }
}
