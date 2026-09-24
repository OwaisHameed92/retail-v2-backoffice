<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Shared\Models\AuditLog;
use InvalidArgumentException;

/**
 * Readable sentences for the billing audit entries (tenant activity tab, invoice activity card).
 */
final class BillingActivity
{
    /** Audit actions this class describes. */
    public static function handles(string $action): bool
    {
        return str_starts_with($action, 'invoice.')
            || str_starts_with($action, 'payment.')
            || str_starts_with($action, 'credit_note.')
            || str_starts_with($action, 'billing.')
            || $action === 'company.overdue';
    }

    public static function describe(AuditLog $entry): string
    {
        $meta = $entry->meta ?? [];
        $after = $entry->after ?? [];
        $before = $entry->before ?? [];
        $number = (string) ($meta['number'] ?? 'the invoice');
        $renewed = (int) ($meta['renewed'] ?? 0);

        return match ($entry->action) {
            'invoice.created' => isset($meta['replaces'])
                ? 'Created a corrected draft to replace '.$meta['replaces']
                : (($meta['auto'] ?? false) ? 'Billing run created a draft invoice for ' : 'Created a draft invoice for ').self::period($after),
            'invoice.updated' => 'Edited the draft invoice (now '.self::money($after['total'] ?? null).')',
            'invoice.deleted' => 'Deleted the draft invoice for '.self::period($before),
            'invoice.issued' => "Issued {$number} for ".self::money($meta['total'] ?? null),
            'invoice.sent' => ($meta['resent'] ?? false) ? "Sent {$number} again" : "Emailed {$number}",
            'invoice.overdue' => "{$number} is overdue (".self::money($meta['balance'] ?? null).' unpaid)',
            'invoice.paid' => "{$number} is paid",
            'invoice.licences_renewed' => $renewed === 0
                ? "No licences needed renewing for {$number}"
                : 'Renewed '.$renewed.' '.($renewed === 1 ? 'till' : 'tills').' until '.self::date((string) ($meta['until'] ?? ''))." for {$number}",
            'invoice.voided' => "Voided {$number}: ".($meta['reason'] ?? ''),
            'credit_note.issued' => 'Issued credit note '.($meta['number'] ?? '').' for '.self::money($meta['amount'] ?? null).' on '.($meta['invoice'] ?? 'an invoice'),
            'payment.recorded' => 'Recorded a '.self::money($meta['amount'] ?? null).' '.mb_strtolower((string) ($meta['method'] ?? '')).' payment ('.$number.')',
            'billing.credit_applied' => 'Applied '.self::money($meta['amount'] ?? null).' of credit to open invoices',
            'billing.settings_updated' => 'Updated billing settings: '.self::fields($after),
            'billing.company_suspended' => 'Suspended the business for unpaid invoice '.$number,
            'billing.trial_reminder_sent' => 'Sent the “trial ending soon” email',
            'billing.trial_ended_sent' => 'Sent the “trial ended” email',
            'company.overdue' => 'Marked the business as overdue'.(isset($meta['reason']) ? ': '.$meta['reason'] : ''),
            default => $entry->action,
        };
    }

    private static function money(mixed $value): string
    {
        try {
            return $value === null ? '£0.00' : BillingFormat::money($value);
        } catch (InvalidArgumentException) {
            return '£0.00';
        }
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function period(array $values): string
    {
        $start = (string) ($values['period_start'] ?? '');
        $end = (string) ($values['period_end'] ?? '');

        if (! self::isDate($start) || ! self::isDate($end)) {
            return 'a period';
        }

        return BillingDates::range(BillingDates::date($start), BillingDates::date($end));
    }

    /** "2026-11-30" → "30 Nov 2026". */
    private static function date(string $ymd): string
    {
        return self::isDate($ymd) ? BillingDates::date($ymd)->format('j M Y') : 'the period end';
    }

    private static function isDate(string $ymd): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) === 1;
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private static function fields(array $after): string
    {
        $names = array_map(fn (string $key) => str_replace('_', ' ', $key), array_keys($after));

        return $names === [] ? 'no changes' : implode(', ', $names);
    }
}
