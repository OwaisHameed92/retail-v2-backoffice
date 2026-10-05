<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\GoCardless\Contracts\GoCardlessClient;
use App\Domain\Billing\GoCardless\Support\SubscriptionAmount;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\BillingStatus;
use App\Domain\Billing\Support\CompanyPricing;
use App\Domain\Billing\Support\MandateDeadline;
use App\Domain\Billing\Support\SetupFeeState;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * The tenant portal My subscription page (modules 1.13 and 4.10, `billing.view`): plan and pricing, what was paid upfront, the Direct
 * Debit (mandate, next collection, deadline) and the invoices. Invoices are read under the tenant scope
 * (CurrentCompany is set by the `company` middleware), so another company's rows never show.
 */
final class PortalBilling
{
    private const INVOICES = 24;

    /**
     * @return array<string, mixed>
     */
    public static function for(Company $company, ?CarbonImmutable $now = null, bool $canManage = true): array
    {
        $account = app(BillingAccounts::class)->for($company);
        $amount = SubscriptionAmount::for($company, $account, $now);
        $cycle = $amount['cycle'];
        $fee = SetupFeeState::for($company, $account);

        return [
            'businessName' => $company->name,
            'status' => BillingStatusData::for(BillingStatus::for($company, $now), portal: true),
            'plan' => CompanyPricing::for($company, $account)->plan?->name,
            'pricing' => [
                'mode' => $amount['mode']->value,
                'modeLabel' => $amount['mode']->label(),
                'unit' => $amount['mode']->unit(),
                'unitPrice' => $amount['unitPrice'] !== null ? BillingFormat::money($amount['unitPrice']) : null,
                'units' => $amount['units'],
                'unitsLabel' => $amount['mode']->units($amount['units']),
                'tills' => $amount['tills'],
                'cycle' => $cycle->value,
                'per' => $cycle->per(),
                'net' => BillingFormat::money($amount['net']),
                'vat' => BillingFormat::money($amount['vat']),
                'gross' => BillingFormat::money($amount['gross']),
                'vatApplies' => ! Money::isZero($amount['vat']),
                'isZero' => Money::isZero($amount['gross']),
            ],
            'upfront' => [
                'recorded' => $account->upfront_recorded_at !== null,
                'amount' => $account->upfront_amount !== null ? BillingFormat::money($account->upfront_amount) : null,
                'method' => $account->upfront_method?->label(),
                'recordedAt' => $account->upfront_recorded_at?->toIso8601String(),
                // The setup fee and the upfront payment are one thing (owner, 2026-10-05), always paid by hand.
                'status' => $fee->status,
                'statusLabel' => $fee->label(),
                'total' => BillingFormat::money($fee->total),
                'owed' => BillingFormat::money($fee->owed()),
            ],
            'directDebit' => self::directDebit($company, $account, $now, $canManage),
            'invoices' => self::invoices(),
            // Module 4.10: account, payments, collections, setup fee and requests; only the owner sends requests.
            ...PortalSubscription::for($company, $account, $amount['tills']),
            'canRequest' => $canManage && ! $company->isCancelled(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function directDebit(Company $company, BillingAccount $account, ?CarbonImmutable $now, bool $canManage): array
    {
        $usable = $account->hasUsableMandate();

        return [
            'directDebit' => $account->isDirectDebit(),
            'available' => app(GoCardlessClient::class)->enabled(),
            'mandate' => [
                'usable' => $usable,
                'status' => $account->gc_mandate_status?->value,
                'statusLabel' => $account->gc_mandate_status?->label() ?? 'Not set up',
                'activeAt' => $account->gc_mandate_active_at?->toIso8601String(),
                'lost' => $account->gc_mandate_lost_at !== null && ! $usable,
            ],
            'nextCollection' => $account->hasLiveSubscription() && $account->gc_next_charge_date !== null ? [
                'date' => $account->gc_next_charge_date->format('Y-m-d'),
                'amount' => BillingFormat::money((string) $account->gc_subscription_amount),
            ] : null,
            'deadline' => MandateDeadline::state($company, $account, $now),
            'canSetUp' => $canManage && $account->isDirectDebit() && ! $usable && ! $company->isCancelled(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function invoices(): array
    {
        return Invoice::query()->where('status', '!=', InvoiceStatus::Draft->value)
            ->orderByDesc('issue_date')->orderByDesc('number')
            ->limit(self::INVOICES)->get()
            ->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'kind' => $invoice->kind->label(),
                'period' => $invoice->period_start->equalTo($invoice->period_end) ? null : BillingDates::range($invoice->period_start, $invoice->period_end),
                'issueDate' => $invoice->issue_date?->format('Y-m-d'),
                'dueDate' => $invoice->due_date?->format('Y-m-d'),
                'total' => BillingFormat::money($invoice->total),
                'balance' => BillingFormat::money($invoice->balance),
                'status' => $invoice->status->value,
                'statusLabel' => $invoice->status->label(),
            ])->values()->all();
    }
}
