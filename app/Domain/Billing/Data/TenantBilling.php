<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Enums\BillingCycle;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\BillingMailer;
use App\Domain\Billing\Support\BillingPeriod;
use App\Domain\Billing\Support\Vat;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * The Billing tab of the admin tenant page: settings, balance, recent invoices and payments, and what the
 * "Create invoice" and "Record payment" dialogs need.
 */
final class TenantBilling
{
    private const RECENT = 10;

    /**
     * @return array<string, mixed>
     */
    public static function for(Company $company, bool $canManage, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $account = app(BillingAccounts::class)->for($company);
        $invoices = Invoice::withoutCompanyScope()->where('company_id', $company->id);
        $open = $invoices->clone()->open()->orderBy('due_date')->orderBy('sequence')->get();
        $overdue = $open->where('status', InvoiceStatus::Overdue);
        $credit = Money::sum(Payment::withoutCompanyScope()->where('company_id', $company->id)->where('unallocated', '>', 0)->pluck('unallocated'));
        $lastPayment = Payment::withoutCompanyScope()->where('company_id', $company->id)->latest('received_at')->first();
        $nextStart = BillingPeriod::nextStart($company, $now);
        $nextEnd = $account->cycle->periodEnd($nextStart);
        $recipients = app(BillingMailer::class)->invoiceRecipients($company);

        return [
            'settings' => [
                'billingName' => $account->billing_name,
                'billingAddress' => $account->billing_address,
                'emails' => $account->emails(),
                'cycle' => $account->cycle->value,
                'paymentTermsDays' => $account->payment_terms_days,
                'vatApplies' => $account->vat_applies,
                'effectiveName' => $account->billing_name ?: ($company->legal_name ?: $company->name),
                'effectiveAddress' => $account->billing_address ?: $company->address,
                'recipients' => array_keys($recipients),
                'recipientsAreOwners' => $account->emails() === [],
            ],
            'summary' => [
                'balance' => BillingFormat::money(Money::sum($open->pluck('balance'))),
                'hasBalance' => $open->isNotEmpty(),
                'openCount' => $open->count(),
                'overdue' => BillingFormat::money(Money::sum($overdue->pluck('balance'))),
                'overdueCount' => $overdue->count(),
                'credit' => BillingFormat::money($credit),
                'creditRaw' => $credit,
                'hasCredit' => ! Money::isZero($credit),
                'lastPayment' => $lastPayment === null ? null : [
                    'amount' => BillingFormat::money($lastPayment->amount),
                    'receivedAt' => $lastPayment->received_at->toIso8601String(),
                    'method' => $lastPayment->method->label(),
                ],
                'nextPeriod' => [
                    'start' => $nextStart->format('Y-m-d'),
                    'end' => $nextEnd->format('Y-m-d'),
                    'label' => BillingDates::range($nextStart, $nextEnd),
                ],
                'suspendedForBilling' => $account->billing_suspended_at !== null,
            ],
            'invoices' => [
                'data' => $invoices->clone()->with('company')->orderByRaw('case when status = ? then 0 else 1 end', [InvoiceStatus::Draft->value])
                    ->latest('issue_date')->latest('created_at')->limit(self::RECENT)->get()
                    ->map(fn (Invoice $invoice) => InvoiceData::row($invoice, $now))->values()->all(),
                'total' => $invoices->clone()->count(),
            ],
            'payments' => [
                'data' => Payment::withoutCompanyScope()->where('company_id', $company->id)->latest('received_at')->limit(self::RECENT)->get()
                    ->map(fn (Payment $payment) => PaymentData::row($payment))->values()->all(),
                'total' => Payment::withoutCompanyScope()->where('company_id', $company->id)->count(),
            ],
            'openInvoices' => self::openInvoices($company),
            'options' => [
                'cycles' => BillingCycle::options(),
                'methods' => PaymentMethod::options(manualOnly: true),
            ],
            'vatEnabled' => Vat::enabled(),
            'vatRate' => BillingFormat::percent(Vat::rate()),
            'canManage' => $canManage,
        ];
    }

    /**
     * Open invoices, oldest due first, for the "Record payment" dialog.
     *
     * @return list<array{id: string, number: string|null, balance: string, balanceLabel: string, dueDate: string|null, status: string, period: string}>
     */
    public static function openInvoices(Company $company): array
    {
        return Invoice::withoutCompanyScope()->where('company_id', $company->id)->open()
            ->orderBy('due_date')->orderBy('sequence')->get()
            ->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'balance' => $invoice->balance,
                'balanceLabel' => BillingFormat::money($invoice->balance),
                'dueDate' => $invoice->due_date?->format('Y-m-d'),
                'status' => $invoice->status->value,
                'period' => BillingDates::range($invoice->period_start, $invoice->period_end),
            ])->values()->all();
    }
}
