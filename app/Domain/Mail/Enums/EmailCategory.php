<?php

namespace App\Domain\Mail\Enums;

/**
 * Groups of tenant emails the owner can stop from going out by themselves (P11, owner 2026-10-07: Admin → Settings →
 * Emails, "Send automatically"). Off: the email is held (logged as "Held") until an admin sends or discards it.
 *
 * Never in a category (always sent): mail a user asked for (forgot password, two-factor), invitations and customer
 * statements a tenant sends, licence keys an admin emails from the licence screens, and our own staff mail (new
 * lead, till and subscription requests, lead rejected, the staff copies of Direct Debit problems).
 */
enum EmailCategory: string
{
    case Invoices = 'invoices';
    case Reminders = 'reminders';
    case SetPassword = 'setPassword';
    case Welcome = 'welcome';
    case OwnerAlerts = 'ownerAlerts';

    public function label(): string
    {
        return match ($this) {
            self::Invoices => 'Invoices and receipts',
            self::Reminders => 'Reminders and notices',
            self::SetPassword => 'Set password link',
            self::Welcome => 'Welcome and licence keys',
            self::OwnerAlerts => 'Owner alerts and digests',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Invoices => 'Every invoice when it is issued, paid invoices sent as receipts, setup fee invoices (also for added tills).',
            self::Reminders => 'Payment and trial reminders, trial ended, Direct Debit setup, failed and cancelled, account suspended and active again, licences renewed.',
            self::SetPassword => 'The link a new owner or user gets to set their first password. A password reset the user asks for always goes.',
            self::Welcome => 'The welcome email with every till’s licence key, sent when a business is set up.',
            self::OwnerAlerts => 'Urgent shop alerts, their “resolved” follow-ups, the daily or weekly digest and anomaly alerts to the business’s owners.',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $category) => $category->value, self::cases());
    }
}
