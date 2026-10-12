<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * "Setup fee paid by … on …" (admin Payment settings, portal billing page), audit BILL-5: read from the payments that
 * stand, not from the `upfront_*` stamp of the first payment ever recorded (a reversed payment or a voided invoice
 * leaves that stamp stale). The first payment, by the day it was received, that is not reversed and still pays a
 * setup fee invoice that is not void: its method, its day and what it put on the setup fee. Without one, a setup fee
 * recorded as £0 ("nothing to pay") shows the stamp; anything else shows nothing recorded. Reads only.
 */
final class SetupFeePayment
{
    /**
     * @return array{recorded: bool, amount: string|null, method: PaymentMethod|null, at: CarbonImmutable|null}
     */
    public static function for(Company $company, BillingAccount $account): array
    {
        $allocations = PaymentAllocation::withoutCompanyScope()->where('company_id', $company->id)->whereNull('released_at')
            ->whereIn('invoice_id', Invoice::withoutCompanyScope()->select('id')->where('company_id', $company->id)
                ->where('kind', InvoiceKind::SetupFee->value)->where('status', '!=', InvoiceStatus::Void->value))
            ->whereIn('payment_id', Payment::withoutCompanyScope()->select('id')->where('company_id', $company->id)->whereNull('reversed_at'))
            ->get();

        $first = $allocations->isEmpty() ? null : Payment::withoutCompanyScope()->whereIn('id', $allocations->pluck('payment_id')->unique()->all())
            ->orderBy('received_at')->orderBy('sequence')->first();

        if ($first !== null) {
            $amount = Money::sum($allocations->where('payment_id', $first->id)->pluck('amount'));

            return ['recorded' => true, 'amount' => $amount, 'method' => $first->method, 'at' => $first->received_at];
        }

        if ($account->upfront_recorded_at !== null && Money::isZero($account->upfront_amount ?? '0.00')) {
            return ['recorded' => true, 'amount' => '0.00', 'method' => $account->upfront_method, 'at' => $account->upfront_recorded_at];
        }

        return ['recorded' => false, 'amount' => null, 'method' => null, 'at' => null];
    }

    /**
     * The `upfront` fields both screens show.
     *
     * @return array{recorded: bool, amount: string|null, method: string|null, recordedAt: string|null}
     */
    public static function display(Company $company, BillingAccount $account): array
    {
        $paid = self::for($company, $account);

        return [
            'recorded' => $paid['recorded'],
            'amount' => $paid['amount'] !== null ? BillingFormat::money($paid['amount']) : null,
            'method' => $paid['method']?->label(),
            'recordedAt' => $paid['at']?->toIso8601String(),
        ];
    }
}
