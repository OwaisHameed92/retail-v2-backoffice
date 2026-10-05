<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Enums\BillingRequestKind;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\GoCardless\Support\SetupFee;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Models\LicenceAlert;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;

/**
 * The parts of My subscription (module 4.10) that PortalBilling (1.13) did not show: the account and what is
 * counted, payment history and scheduled Direct Debit collections, the setup fee and its instalments, and the
 * business's requests to Switch & Save (cancel, change bank account). Read under the tenant scope.
 */
final class PortalSubscription
{
    private const PAYMENTS = 24;

    /**
     * @return array<string, mixed>
     */
    public static function for(Company $company, BillingAccount $account, int $tills): array
    {
        return [
            'account' => [
                'status' => $company->status->value,
                'statusLabel' => $company->status->label(),
                'trialEndsAt' => $company->trial_ends_at?->toIso8601String(),
                'customerSince' => ($company->activated_at ?? $company->created_at)?->toIso8601String(),
                'tills' => $tills,
                'shops' => Branch::query()->active()->count(),
                'cancelled' => $company->isCancelled(),
            ],
            'payments' => self::payments(),
            'collections' => self::collections(),
            'setupFee' => self::setupFee($company, $account),
            'requests' => self::requests(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function payments(): array
    {
        return Payment::query()->orderByDesc('received_at')->orderByDesc('number')->limit(self::PAYMENTS)->get()
            ->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'number' => $payment->number,
                'receivedAt' => $payment->received_at->toIso8601String(),
                'method' => $payment->method->label(),
                'amount' => BillingFormat::money($payment->amount),
                'reversed' => $payment->reversed_at !== null,
            ])->values()->all();
    }

    /**
     * Direct Debit payments GoCardless has scheduled or is collecting, soonest first.
     *
     * @return list<array<string, mixed>>
     */
    private static function collections(): array
    {
        return GoCardlessPayment::query()->orderBy('charge_date')->limit(50)->get()
            ->filter(fn (GoCardlessPayment $payment) => $payment->status->isPending())
            ->take(12)
            ->map(fn (GoCardlessPayment $payment) => [
                'id' => $payment->id,
                'chargeDate' => $payment->charge_date?->format('Y-m-d'),
                'amount' => BillingFormat::money($payment->amount),
                'what' => $payment->description ?? ($payment->instalments !== null && $payment->instalments > 1
                    ? "Setup fee, part {$payment->instalment} of {$payment->instalments}" : 'Subscription'),
                'statusLabel' => $payment->status->label(),
            ])->values()->all();
    }

    /**
     * The setup fee: its invoices once charged (one per instalment), else what will be charged and how.
     *
     * @return array<string, mixed>|null
     */
    private static function setupFee(Company $company, BillingAccount $account): ?array
    {
        $invoices = Invoice::query()->where('kind', InvoiceKind::SetupFee->value)->where('status', '!=', InvoiceStatus::Draft->value)
            ->orderBy('due_date')->orderBy('number')->get();

        if ($invoices->isNotEmpty()) {
            return [
                'charged' => true,
                'method' => null,
                'total' => BillingFormat::money($invoices->reduce(fn (string $sum, Invoice $invoice) => Money::add($sum, $invoice->total), '0.00')),
                'parts' => $invoices->values()->map(fn (Invoice $invoice, int $i) => [
                    'label' => $invoices->count() > 1 ? 'Part '.($i + 1).' of '.$invoices->count() : 'Setup fee',
                    'dueDate' => $invoice->due_date?->format('Y-m-d'),
                    'amount' => BillingFormat::money($invoice->total),
                    'status' => $invoice->status->value,
                    'statusLabel' => $invoice->status->label(),
                    'number' => $invoice->number,
                ])->all(),
            ];
        }

        if ($account->setup_fee_invoiced_at !== null || $account->upfront_recorded_at !== null) {
            return null; // settled upfront (shown as "Paid upfront") or nothing was due
        }

        $schedule = SetupFee::schedule($company, $account);

        return $schedule === [] ? null : [
            'charged' => false,
            'method' => 'manual', // always paid by hand (owner rule 2026-10-05)
            'total' => BillingFormat::money(SetupFee::totals($company, $account)['gross']),
            'parts' => array_map(fn (array $part, int $i) => [
                'label' => count($schedule) > 1 ? 'Part '.($i + 1).' of '.count($schedule) : 'Setup fee',
                'dueDate' => null,
                'amount' => BillingFormat::money($part['gross']),
                'status' => 'planned',
                'statusLabel' => 'Not paid yet',
                'number' => null,
            ], $schedule, array_keys($schedule)),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function requests(): array
    {
        return LicenceAlert::query()->where('type', LicenceAlertType::SubscriptionRequested->value)
            ->orderByRaw('case when resolved_at is null then 0 else 1 end')->orderByDesc('last_seen_at')->limit(5)->get()
            ->map(function (LicenceAlert $alert) {
                $kind = BillingRequestKind::tryFrom((string) ($alert->details['kind'] ?? '')) ?? BillingRequestKind::Cancel;

                return [
                    'id' => $alert->id,
                    'kind' => $kind->value,
                    'kindLabel' => $kind->label(),
                    'requestedBy' => $alert->details['requestedBy'] ?? null,
                    'sentAt' => $alert->first_seen_at->toIso8601String(),
                    'count' => $alert->count,
                    'done' => $alert->resolved_at !== null,
                    'doneAt' => $alert->resolved_at?->toIso8601String(),
                ];
            })->values()->all();
    }
}
