<?php

namespace App\Domain\Billing\GoCardless\Support;

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\GoCardless\Enums\MandateStatus;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Shared\Support\Money;
use Carbon\CarbonImmutable;

/**
 * Direct Debit figures for the Billing overview: working mandates, Direct Debit customers without one,
 * collections still to come and payments that failed in the last 30 days. Sums are done on row values (decimal
 * strings), never SQL SUM.
 */
final class DirectDebitStats
{
    /**
     * @return array{enabled: bool, activeMandates: int, withoutMandate: int, upcoming: array{count: int, amount: string}, failed: array{count: int, amount: string}}
     */
    public static function for(CarbonImmutable $now, bool $enabled): array
    {
        $usable = [MandateStatus::PendingSubmission->value, MandateStatus::Submitted->value, MandateStatus::Active->value];
        $directDebit = BillingAccount::withoutCompanyScope()->where('billing_mode', BillingMode::DirectDebit->value);

        $upcoming = GoCardlessPayment::withoutCompanyScope()->whereIn('status', PaymentStatus::pendingValues())->pluck('amount');
        $failed = GoCardlessPayment::withoutCompanyScope()->whereIn('status', [PaymentStatus::Failed->value, PaymentStatus::ChargedBack->value])
            ->where('failed_at', '>=', $now->subDays(30))->pluck('amount');

        return [
            'enabled' => $enabled,
            'activeMandates' => $directDebit->clone()->whereIn('gc_mandate_status', $usable)->count(),
            'withoutMandate' => $directDebit->clone()->where(fn ($q) => $q->whereNull('gc_mandate_status')->orWhereNotIn('gc_mandate_status', $usable))->count(),
            'upcoming' => ['count' => $upcoming->count(), 'amount' => BillingFormat::money(Money::sum($upcoming))],
            'failed' => ['count' => $failed->count(), 'amount' => BillingFormat::money(Money::sum($failed))],
        ];
    }

    /**
     * Next collections, soonest first, for the overview list.
     *
     * @return list<array<string, string|null>>
     */
    public static function nextCollections(int $limit = 6): array
    {
        return GoCardlessPayment::withoutCompanyScope()->with(['invoice', 'company'])->whereIn('status', PaymentStatus::pendingValues())
            ->orderBy('charge_date')->limit($limit)->get()
            ->map(fn (GoCardlessPayment $payment) => [
                'id' => $payment->id,
                'companyId' => $payment->company_id,
                'companyName' => $payment->company->name ?? 'Deleted business',
                'amount' => BillingFormat::money($payment->amount),
                'chargeDate' => $payment->charge_date?->format('Y-m-d'),
                'invoiceId' => $payment->invoice_id,
                'invoiceNumber' => $payment->invoice?->number,
                'statusLabel' => $payment->status->label(),
            ])->values()->all();
    }
}
