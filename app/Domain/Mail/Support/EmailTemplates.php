<?php

namespace App\Domain\Mail\Support;

use App\Domain\Mail\Mailables\AccountReactivatedMail;
use App\Domain\Mail\Mailables\AccountSuspendedMail;
use App\Domain\Mail\Mailables\AdminNewLeadMail;
use App\Domain\Mail\Mailables\BrandedMailable;
use App\Domain\Mail\Mailables\InvoiceMail;
use App\Domain\Mail\Mailables\LeadRejectedMail;
use App\Domain\Mail\Mailables\LicenceKeyMail;
use App\Domain\Mail\Mailables\LicenceRenewedMail;
use App\Domain\Mail\Mailables\SetPasswordMail;
use App\Domain\Mail\Mailables\TrialEndedMail;
use App\Domain\Mail\Mailables\TrialReminderMail;
use App\Domain\Mail\Mailables\WelcomeTenantMail;

/**
 * Every branded email, in customer-lifecycle order. The admin template screen and previews read this list.
 */
final class EmailTemplates
{
    /** @var list<class-string<BrandedMailable>> */
    public const MAILABLES = [
        WelcomeTenantMail::class,
        SetPasswordMail::class,
        LicenceKeyMail::class,
        TrialReminderMail::class,
        TrialEndedMail::class,
        InvoiceMail::class,
        LicenceRenewedMail::class,
        AccountSuspendedMail::class,
        AccountReactivatedMail::class,
        AdminNewLeadMail::class,
        LeadRejectedMail::class,
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(fn (string $class) => $class::templateKey(), self::MAILABLES);
    }

    /**
     * @return class-string<BrandedMailable>|null
     */
    public static function find(string $key): ?string
    {
        foreach (self::MAILABLES as $class) {
            if ($class::templateKey() === $key) {
                return $class;
            }
        }

        return null;
    }

    /** A sample mailable for the key, or null when the key is unknown. */
    public static function sample(string $key): ?BrandedMailable
    {
        $class = self::find($key);

        return $class === null ? null : $class::sample();
    }

    /** "welcome-tenant" → "Welcome and licence keys"; unknown keys (framework mail) are sentence-cased. */
    public static function label(string $key): string
    {
        $class = self::find($key);

        return $class !== null ? $class::templateLabel() : ucfirst(str_replace('-', ' ', $key));
    }

    /**
     * Rows for the admin template list, with the sample subject line.
     *
     * @return list<array{key: string, label: string, description: string, audience: string, subject: string}>
     */
    public static function all(): array
    {
        return array_map(fn (string $class) => [
            'key' => $class::templateKey(),
            'label' => $class::templateLabel(),
            'description' => $class::templateDescription(),
            'audience' => $class::audience(),
            'subject' => $class::sample()->subjectLine(),
        ], self::MAILABLES);
    }
}
