<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * The portal's "overdue" warning (audit UI-5): what is overdue and the day billing:run suspends the business and its
 * tills lock, read from the same rule as SuspendForUnpaidInvoices (an invoice overdue more than
 * `billing.suspend_after_days` after its due date, or after a Direct Debit failure reopened it). It only reads: the
 * grace and suspension rules are unchanged. Null when nothing is overdue, or the business is already suspended or
 * cancelled (the portal says so elsewhere).
 */
final class OverdueNotice
{
    /** billing:run runs at 06:00 (routes/console.php): a grace that ends later in the day is acted on the next morning. */
    private const RUN_HOUR = 6;

    /**
     * @return array{amountDue: string, invoices: int, lockOn: string|null, daysLeft: int|null}|null
     */
    public static function state(Company $company, ?CarbonImmutable $now = null): ?array
    {
        if (! in_array($company->status, [CompanyStatus::Trial, CompanyStatus::Active, CompanyStatus::Overdue], true)) {
            return null;
        }

        $invoices = Invoice::withoutCompanyScope()->where('company_id', $company->id)
            ->where('status', InvoiceStatus::Overdue->value)
            ->get(['id', 'balance', 'due_date', 'reopened_at', 'suspension_triggered_at']);

        if ($invoices->isEmpty()) {
            return null;
        }

        $now ??= CarbonImmutable::now();
        $today = BillingDates::today($now);
        $days = max(0, (int) config('billing.suspend_after_days', 14));

        // An invoice that already suspended the business once (lifted by hand) does not suspend it again.
        $lockOn = $invoices->whereNull('suspension_triggered_at')
            ->map(fn (Invoice $invoice) => self::lockDay($invoice, $days))
            ->filter()
            ->min();

        if ($lockOn !== null && $lockOn->lessThan($today)) {
            $lockOn = $today; // the next billing:run
        }

        return [
            'amountDue' => Money::sum($invoices->pluck('balance')),
            'invoices' => $invoices->count(),
            'lockOn' => $lockOn?->format('Y-m-d'),
            'daysLeft' => $lockOn !== null ? (int) $today->diffInDays($lockOn) : null,
        ];
    }

    /** The calendar day billing:run suspends for this invoice (SuspendForUnpaidInvoices::due). */
    private static function lockDay(Invoice $invoice, int $days): ?CarbonImmutable
    {
        if ($invoice->due_date === null) {
            return null;
        }

        // Due date before "today minus the grace": the morning after the grace's last day.
        $day = $invoice->due_date->addDays($days + 1);

        if ($invoice->reopened_at !== null) {
            $ends = $invoice->reopened_at->addDays($days);
            $local = $ends->setTimezone(Country::zone());
            $reopened = BillingDates::localDate($ends)->addDays((int) $local->format('G') >= self::RUN_HOUR ? 1 : 0);
            $day = $reopened->greaterThan($day) ? $reopened : $day;
        }

        return $day;
    }
}
