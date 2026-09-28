<?php

namespace App\Domain\Billing\GoCardless\Data;

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\Enums\SetupFeeMethod;
use App\Domain\Billing\GoCardless\Contracts\GoCardlessClient;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\GoCardless\Support\SetupFee;
use App\Domain\Billing\GoCardless\Support\SubscriptionAmount;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;

/**
 * The "Direct Debit" part of the admin tenant Billing tab: how the business pays, its setup fee, mandate,
 * subscription (with what it should be for the live tills) and recent GoCardless payments.
 */
final class DirectDebitData
{
    private const RECENT = 10;

    /**
     * @return array<string, mixed>
     */
    public static function for(Company $company): array
    {
        $account = app(BillingAccounts::class)->for($company);
        $client = app(GoCardlessClient::class);
        $setup = SetupFee::totals($company, $account);
        $expected = SubscriptionAmount::for($company, $account);
        $query = GoCardlessPayment::withoutCompanyScope()->with('invoice')->where('company_id', $company->id);

        return [
            'enabled' => $client->enabled(),
            'environment' => $client->environment(),
            'mode' => $account->billing_mode->value,
            'setupFee' => [
                'plan' => SetupFee::planFee($company),
                'override' => $account->setup_fee_override,
                'net' => BillingFormat::money($setup['net']),
                'gross' => BillingFormat::money($setup['gross']),
                'hasFee' => ! Money::isZero($setup['net']),
                'method' => $account->setup_fee_method->value,
                'instalments' => $account->setup_fee_instalments,
                'invoicedAt' => $account->setup_fee_invoiced_at?->toIso8601String(),
            ],
            'mandate' => [
                'id' => $account->gc_mandate_id,
                'status' => $account->gc_mandate_status?->value,
                'statusLabel' => $account->gc_mandate_status?->label() ?? 'Not set up',
                'usable' => $account->hasUsableMandate(),
                'lostAt' => $account->gc_mandate_lost_at?->toIso8601String(),
                'activeAt' => $account->gc_mandate_active_at?->toIso8601String(),
                'setupSentAt' => $account->gc_setup_sent_at?->toIso8601String(),
            ],
            'subscription' => [
                'id' => $account->gc_subscription_id,
                'status' => $account->gc_subscription_status?->value,
                'statusLabel' => $account->gc_subscription_status?->label() ?? 'None',
                'live' => $account->hasLiveSubscription(),
                'amount' => $account->gc_subscription_amount !== null ? BillingFormat::money($account->gc_subscription_amount) : null,
                'cycle' => $account->gc_subscription_cycle?->value,
                'nextChargeDate' => $account->gc_next_charge_date?->format('Y-m-d'),
                'expected' => BillingFormat::money($expected['gross']),
                'expectedTills' => $expected['tills'],
                'inStep' => $account->gc_subscription_amount !== null && Money::equals($account->gc_subscription_amount, $expected['gross']) && $account->gc_subscription_cycle === $account->cycle,
                'reconciledAt' => $account->gc_reconciled_at?->toIso8601String(),
            ],
            'payments' => [
                'data' => $query->clone()->latest('charge_date')->latest('created_at')->limit(self::RECENT)->get()
                    ->map(fn (GoCardlessPayment $payment) => [
                        'id' => $payment->id,
                        'gcPaymentId' => $payment->gc_payment_id,
                        'kind' => $payment->kind->value,
                        'amount' => BillingFormat::money($payment->amount),
                        'chargeDate' => $payment->charge_date?->format('Y-m-d'),
                        'status' => $payment->status->value,
                        'statusLabel' => $payment->status->label(),
                        'invoiceId' => $payment->invoice_id,
                        'invoiceNumber' => $payment->invoice?->number,
                        'instalment' => $payment->instalments !== null ? "{$payment->instalment} of {$payment->instalments}" : null,
                        'failureReason' => $payment->failure_reason,
                    ])->values()->all(),
                'total' => $query->clone()->count(),
            ],
            'options' => [
                'modes' => BillingMode::options(),
                'setupFeeMethods' => SetupFeeMethod::options(),
                'maxInstalments' => (int) config('billing.direct_debit.max_instalments', 12),
            ],
            'graceDays' => (int) config('billing.direct_debit.mandate_grace_days', 3),
        ];
    }
}
