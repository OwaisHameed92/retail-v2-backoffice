<?php

namespace App\Domain\Mail\Mailables;

use App\Domain\Mail\Data\SetPasswordData;
use App\Domain\Mail\Support\MailFormat;
use App\Domain\Shared\Country\LocalText;
use Illuminate\Mail\Mailables\Content;

/**
 * Branded password link for portal users: "set your password" for a new owner account, "reset" otherwise.
 * The forgot-password flow sends it through User::sendPasswordResetNotification().
 */
final class SetPasswordMail extends BrandedMailable
{
    public function __construct(public SetPasswordData $data) {}

    public static function templateKey(): string
    {
        return 'set-password';
    }

    public static function templateLabel(): string
    {
        return 'Set or reset password';
    }

    public static function templateDescription(): string
    {
        return 'A one-time link for a portal user to set their first password, or to reset it from "Forgot password".';
    }

    public static function sample(): static
    {
        return new self(new SetPasswordData(
            name: 'Aisha Khan',
            email: LocalText::domains('aisha@khanminimart.co.uk'),
            url: config('sspos.portal_url').LocalText::domains('/reset-password/sample-token?email=aisha%40khanminimart.co.uk'),
            expiresInMinutes: 60,
            firstTime: true,
            businessName: 'Khan Mini Mart',
        ));
    }

    public function subjectLine(): string
    {
        return $this->data->firstTime ? 'Set your Switch & Save password' : 'Reset your Switch & Save password';
    }

    public function companyId(): ?string
    {
        return $this->data->companyId;
    }

    public function logMeta(): array
    {
        return ['purpose' => $this->data->firstTime ? 'setup' : 'reset'];
    }

    public function content(): Content
    {
        $minutes = $this->data->expiresInMinutes;

        return new Content(markdown: 'mail.set-password', with: [
            'firstName' => MailFormat::firstName($this->data->name),
            'expiresIn' => match (true) {
                $minutes >= 1440 && $minutes % 1440 === 0 => MailFormat::count(intdiv($minutes, 1440), 'day'),
                $minutes >= 60 && $minutes % 60 === 0 => MailFormat::count(intdiv($minutes, 60), 'hour'),
                default => MailFormat::count($minutes, 'minute'),
            },
            'loginUrl' => config('sspos.portal_url').'/login',
        ]);
    }
}
