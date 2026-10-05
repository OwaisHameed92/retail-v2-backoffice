<?php

namespace App\Http\Controllers\Admin\Billing;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Data\InvoiceData;
use App\Domain\Billing\Data\PaymentData;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\GoCardless\Contracts\GoCardlessClient;
use App\Domain\Billing\GoCardless\Support\DirectDebitStats;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Queries\BillingStateOverview;
use App\Domain\Billing\Queries\BillingStats;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Cash due": what is owed, overdue and due this week across every tenant, drafts to review and recent payments,
 * and every business by billing state (who is paid, on trial, waiting for Direct Debit, overdue, suspended).
 */
class BillingOverviewController extends Controller
{
    private const LIMIT = 8;

    public function __invoke(Request $request, GoCardlessClient $client): Response
    {
        $now = CarbonImmutable::now();
        $today = BillingDates::today($now);
        $stats = BillingStats::for($now);
        $row = fn (Invoice $invoice) => InvoiceData::row($invoice, $now);
        $open = fn () => Invoice::withoutCompanyScope()->with('company')->open();

        return Inertia::render('admin/billing/overview', [
            'stats' => [
                'cashDue' => ['count' => $stats['cashDue']['count'], 'amount' => BillingFormat::money($stats['cashDue']['amount'])],
                'overdue' => ['count' => $stats['overdue']['count'], 'amount' => BillingFormat::money($stats['overdue']['amount'])],
                'dueThisWeek' => ['count' => $stats['dueThisWeek']['count'], 'amount' => BillingFormat::money($stats['dueThisWeek']['amount'])],
                'collectedThisMonth' => ['count' => $stats['collectedThisMonth']['count'], 'amount' => BillingFormat::money($stats['collectedThisMonth']['amount'])],
                'drafts' => ['count' => $stats['drafts']['count'], 'amount' => BillingFormat::money($stats['drafts']['amount'])],
                'creditHeld' => BillingFormat::money($stats['creditHeld']),
                'suspendedForBilling' => $stats['suspendedForBilling'],
            ],
            'overdue' => $open()->where('invoices.status', InvoiceStatus::Overdue->value)->orderBy('due_date')->orderBy('sequence')->limit(self::LIMIT)->get()->map($row)->values(),
            'dueSoon' => $open()->where('invoices.status', '!=', InvoiceStatus::Overdue->value)
                ->where('due_date', '>=', $today->format('Y-m-d'))->where('due_date', '<=', $today->addDays(6)->format('Y-m-d'))
                ->orderBy('due_date')->orderBy('sequence')->limit(self::LIMIT)->get()->map($row)->values(),
            'drafts' => Invoice::withoutCompanyScope()->with('company')->where('status', InvoiceStatus::Draft->value)
                ->orderBy('period_start')->limit(self::LIMIT)->get()->map($row)->values(),
            'recentPayments' => Payment::withoutCompanyScope()->latest('received_at')->limit(5)->get()->map(fn (Payment $payment) => PaymentData::row($payment))->values(),
            'suspended' => BillingAccount::withoutCompanyScope()->with('company')->whereNotNull('billing_suspended_at')
                ->whereHas('company', fn ($q) => $q->where('status', CompanyStatus::Suspended->value))->orderBy('billing_suspended_at')->limit(self::LIMIT)->get()
                ->map(fn (BillingAccount $account) => [
                    'companyId' => $account->company_id,
                    'name' => $account->company->name ?? 'Deleted business',
                    'since' => $account->billing_suspended_at?->toIso8601String(),
                    'invoiceId' => $account->suspension_invoice_id,
                ])->values(),
            'directDebit' => [
                ...DirectDebitStats::for($now, $client->enabled()),
                'environment' => $client->environment(),
                'next' => DirectDebitStats::nextCollections(),
            ],
            // Every business by billing state (paid, trial, waiting for Direct Debit, overdue…), filtered by ?state=.
            'businesses' => BillingStateOverview::for(is_string($request->query('state')) ? $request->query('state') : null, $now),
            'settings' => [
                'suspendAfterDays' => (int) config('billing.suspend_after_days', 7),
                'generateDaysBefore' => (int) config('billing.generate.days_before', 7),
                'autoIssue' => (bool) config('billing.generate.auto_issue', false),
            ],
            'canManage' => $request->user('admin')?->hasAbility(AdminRole::BILLING_MANAGE) ?? false,
        ]);
    }
}
