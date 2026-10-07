<?php

namespace App\Domain\Mail\Support;

use App\Domain\Billing\Support\ManualCollection;
use App\Domain\Mail\Mailables\AccountReactivatedMail;
use App\Domain\Mail\Mailables\AccountSuspendedMail;
use App\Domain\Mail\Mailables\AdminNewLeadMail;
use App\Domain\Mail\Mailables\AdminSubscriptionRequestMail;
use App\Domain\Mail\Mailables\AdminTillRequestMail;
use App\Domain\Mail\Mailables\AnomalyAlertMail;
use App\Domain\Mail\Mailables\BrandedMailable;
use App\Domain\Mail\Mailables\CustomerStatementMail;
use App\Domain\Mail\Mailables\DirectDebitCancelledMail;
use App\Domain\Mail\Mailables\DirectDebitFailedMail;
use App\Domain\Mail\Mailables\DirectDebitSetupMail;
use App\Domain\Mail\Mailables\InvoiceMail;
use App\Domain\Mail\Mailables\LeadRejectedMail;
use App\Domain\Mail\Mailables\LicenceKeyMail;
use App\Domain\Mail\Mailables\LicenceRenewedMail;
use App\Domain\Mail\Mailables\OwnerAlertMail;
use App\Domain\Mail\Mailables\OwnerAlertResolvedMail;
use App\Domain\Mail\Mailables\OwnerDigestMail;
use App\Domain\Mail\Mailables\PaymentReminderMail;
use App\Domain\Mail\Mailables\PlanChangedMail;
use App\Domain\Mail\Mailables\PortalInvitationMail;
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
        PortalInvitationMail::class,
        LicenceKeyMail::class,
        TrialReminderMail::class,
        TrialEndedMail::class,
        DirectDebitSetupMail::class,
        InvoiceMail::class,
        DirectDebitFailedMail::class,
        DirectDebitCancelledMail::class,
        LicenceRenewedMail::class,
        AccountSuspendedMail::class,
        AccountReactivatedMail::class,
        PlanChangedMail::class,
        AdminNewLeadMail::class,
        LeadRejectedMail::class,
        CustomerStatementMail::class,
        AdminTillRequestMail::class,
        AdminSubscriptionRequestMail::class,
        OwnerAlertMail::class,
        OwnerAlertResolvedMail::class,
        OwnerDigestMail::class,
        AnomalyAlertMail::class,
    ];

    /**
     * Pakistan plan P5: emails only an instance that collects fees by hand sends (listed after the invoice email
     * there, where the Direct Debit emails are left out: they are never sent); a Direct Debit (GB) instance lists
     * exactly MAILABLES.
     *
     * @var list<class-string<BrandedMailable>>
     */
    public const MANUAL_BILLING = [PaymentReminderMail::class];

    /** @var list<class-string<BrandedMailable>> */
    private const DIRECT_DEBIT = [DirectDebitSetupMail::class, DirectDebitFailedMail::class, DirectDebitCancelledMail::class];

    /**
     * The emails of this instance: MAILABLES, plus MANUAL_BILLING where fees are collected by hand.
     *
     * @return list<class-string<BrandedMailable>>
     */
    public static function mailables(): array
    {
        if (! ManualCollection::active()) {
            return self::MAILABLES;
        }

        $list = array_values(array_diff(self::MAILABLES, self::DIRECT_DEBIT));
        $at = (int) array_search(InvoiceMail::class, $list, true) + 1;

        return [...array_slice($list, 0, $at), ...self::MANUAL_BILLING, ...array_slice($list, $at)];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(fn (string $class) => $class::templateKey(), self::mailables());
    }

    /**
     * @return class-string<BrandedMailable>|null
     */
    public static function find(string $key): ?string
    {
        foreach (self::mailables() as $class) {
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
        ], self::mailables());
    }
}
