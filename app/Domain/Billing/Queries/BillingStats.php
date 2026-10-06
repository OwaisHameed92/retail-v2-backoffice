<?php

namespace App\Domain\Billing\Queries;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Enums\CompanyStatus;
use Carbon\CarbonImmutable;

/**
 * Cash numbers across every tenant, for the billing overview and the admin dashboard (module 1.9):
 *
 *     $stats = BillingStats::for(CarbonImmutable::now());
 *     $stats['cashDue']['amount']; // "1234.50" (pounds, decimal string)
 *
 * Sums are done with bcmath on the row values (SQLite's SUM would return floats).
 */
final class BillingStats
{
    /**
     * @return array{
     *     cashDue: array{count: int, amount: string},
     *     overdue: array{count: int, amount: string},
     *     dueThisWeek: array{count: int, amount: string},
     *     collectedThisMonth: array{count: int, amount: string},
     *     drafts: array{count: int, amount: string},
     *     creditHeld: string,
     *     suspendedForBilling: int
     * }
     */
    public static function for(CarbonImmutable $now): array
    {
        $today = BillingDates::today($now);
        $open = Invoice::withoutCompanyScope()->open()->get(['id', 'status', 'balance', 'due_date']);
        $overdue = $open->where('status', InvoiceStatus::Overdue);
        $weekEnd = $today->addDays(6);
        $dueSoon = $open->filter(fn (Invoice $invoice) => $invoice->status !== InvoiceStatus::Overdue
            && $invoice->due_date !== null
            && $invoice->due_date->betweenIncluded($today, $weekEnd));

        $monthStart = CarbonImmutable::parse($now->setTimezone(Country::zone())->format('Y-m-01').' 00:00:00', Country::zone())->utc();
        $collected = Payment::withoutCompanyScope()->where('received_at', '>=', $monthStart)->where('received_at', '<=', $now)->pluck('amount');
        $drafts = Invoice::withoutCompanyScope()->where('status', InvoiceStatus::Draft->value)->pluck('total');

        return [
            'cashDue' => ['count' => $open->count(), 'amount' => Money::sum($open->pluck('balance'))],
            'overdue' => ['count' => $overdue->count(), 'amount' => Money::sum($overdue->pluck('balance'))],
            'dueThisWeek' => ['count' => $dueSoon->count(), 'amount' => Money::sum($dueSoon->pluck('balance'))],
            'collectedThisMonth' => ['count' => $collected->count(), 'amount' => Money::sum($collected)],
            'drafts' => ['count' => $drafts->count(), 'amount' => Money::sum($drafts)],
            'creditHeld' => Money::sum(Payment::withoutCompanyScope()->where('unallocated', '>', 0)->pluck('unallocated')),
            'suspendedForBilling' => BillingAccount::withoutCompanyScope()->whereNotNull('billing_suspended_at')
                ->whereHas('company', fn ($q) => $q->where('status', CompanyStatus::Suspended->value))->count(),
        ];
    }
}
