<?php

namespace App\Domain\OnlineStore\Mail\Sending;

use App\Domain\OnlineStore\Models\OnlineStoreMailSettings;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Which server a shop's customer email goes through, at send time (OS-10). Used by OnlineOrderMailable::send():
 *
 * - ownServer(): the store's settings when its own SMTP server is set up and passed a test email, else null (the
 *   platform mailer sends it, in the shop's name).
 * - failed(): the own server failed: the plain-words reason and time are stored for the Emails tab and a safe
 *   warning is logged (no password, no message content); the caller then sends through the platform mailer.
 *
 * Queue workers have no signed-in business, so the settings are read by the email's own company id explicitly.
 */
final class StoreMailServer
{
    public function __construct(private readonly StoreSmtpMailer $mailers) {}

    public function ownServer(?string $companyId): ?OnlineStoreMailSettings
    {
        if ($companyId === null) {
            return null;
        }

        $settings = OnlineStoreMailSettings::withoutCompanyScope()->where('company_id', $companyId)->first();

        return $settings !== null && $settings->usesOwnServer() ? $settings : null;
    }

    /**
     * @throws StoreSmtpUnavailable
     */
    public function mailer(OnlineStoreMailSettings $settings): Mailer
    {
        return $this->mailers->make($settings);
    }

    public function failed(OnlineStoreMailSettings $settings, Throwable $error, string $template): string
    {
        $reason = SmtpErrorText::for($error, $settings);

        OnlineStoreMailSettings::withoutCompanyScope()->whereKey($settings->id)->where('company_id', $settings->company_id)
            ->update(['last_error' => $reason, 'last_error_at' => now(), 'updated_at' => now()]);

        Log::warning('Online store email: the shop\'s own email server failed, sent through the platform instead', [
            'company_id' => $settings->company_id,
            'template' => $template,
            'host' => $settings->smtp_host,
            'reason' => $reason,
        ]);

        return $reason;
    }
}
