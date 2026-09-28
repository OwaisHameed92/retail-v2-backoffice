<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\InvoiceBalance;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Takes back money we recorded but did not keep (a Direct Debit charged back, module 1.12): every allocation of the
 * payment is released and nothing goes to credit, so the invoices it paid are owed again (a paid invoice goes back
 * to issued; billing:run marks it overdue after its due date, and the usual suspension follows). Licences already
 * renewed stay renewed until then. Once per payment.
 */
class ReversePayment
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly RecordAudit $audit,
    ) {}

    public function handle(Payment $payment, string $reason): Payment
    {
        /** @var Company $company */
        $company = Company::withTrashed()->findOrFail($payment->company_id);

        return DB::transaction(function () use ($payment, $company, $reason) {
            $now = CarbonImmutable::now();
            $this->accounts->lock($company);
            $payment = Payment::withoutCompanyScope()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->reversed_at !== null) {
                return $payment;
            }

            $reopened = [];

            foreach (PaymentAllocation::withoutCompanyScope()->where('payment_id', $payment->id)->whereNull('released_at')->lockForUpdate()->get() as $allocation) {
                $allocation->released_at = $now;
                $allocation->save();

                $invoice = Invoice::withoutCompanyScope()->lockForUpdate()->findOrFail($allocation->invoice_id);

                if ($invoice->status === InvoiceStatus::Void) {
                    continue;
                }

                if ($invoice->status === InvoiceStatus::Paid) {
                    $invoice->status = InvoiceStatus::Issued;
                    $invoice->paid_at = null;
                }

                InvoiceBalance::refresh($invoice);
                $reopened[] = $invoice->number;
            }

            $payment->forceFill([
                'unallocated' => '0.00',
                'reversed_at' => $now,
                'reversal_reason' => mb_substr($reason, 0, 500),
            ])->save();

            $this->audit->handle('payment.reversed', $payment, null, ['reversed_at' => $now->toIso8601String()], [
                'number' => $payment->number,
                'amount_label' => BillingFormat::money($payment->amount),
                'reason' => $reason,
                'invoices' => $reopened,
            ], companyId: $company->id);

            return $payment;
        });
    }
}
