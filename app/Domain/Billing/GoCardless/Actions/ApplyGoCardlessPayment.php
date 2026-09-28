<?php

namespace App\Domain\Billing\GoCardless\Actions;

use App\Domain\Billing\Actions\RecordPayment;
use App\Domain\Billing\Actions\ReversePayment;
use App\Domain\Billing\Actions\SendInvoice;
use App\Domain\Billing\Data\NewPayment;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\GoCardless\Data\GcPayment;
use App\Domain\Billing\GoCardless\Enums\PaymentKind;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\GoCardless\Support\DirectDebitMailer;
use App\Domain\Billing\GoCardless\Support\Pence;
use App\Domain\Billing\GoCardless\Support\SubscriptionInvoices;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Brings our records in line with one GoCardless payment (webhook or reconcile; safe to repeat, in any order):
 *
 * - first seen: stored, and a subscription payment gets its invoice (SubscriptionInvoices) and the invoice email;
 * - confirmed / paid out: RecordPayment (method Direct Debit, gateway "gocardless" + payment id, so it is recorded
 *   once) on its invoice; the invoice is paid and its tills renewed to the period end (SettleInvoice);
 * - failed / charged back: a payment we recorded is reversed (the invoice is owed again) and the owners get the
 *   "Direct Debit failed" email once; billing:run then marks it overdue and suspends as for any unpaid invoice.
 */
class ApplyGoCardlessPayment
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly SubscriptionInvoices $invoices,
        private readonly RecordPayment $recordPayment,
        private readonly ReversePayment $reversePayment,
        private readonly SendInvoice $sendInvoice,
        private readonly DirectDebitMailer $mailer,
        private readonly RecordAudit $audit,
    ) {}

    public function handle(Company $company, GcPayment $remote, ?string $failureReason = null): GoCardlessPayment
    {
        $row = $this->row($company, $remote);
        $before = $row->status;

        $row->forceFill([
            'status' => $remote->status,
            'amount' => Pence::toPounds($remote->amountPence),
            'charge_date' => $remote->chargeDate !== null ? BillingDates::date($remote->chargeDate) : $row->charge_date,
        ])->save();

        if ($before !== $remote->status) {
            $this->audit->handle('billing.dd_payment_'.$remote->status->value, $row, ['status' => $before->value], ['status' => $remote->status->value], [
                'gc_payment' => $remote->id,
                'amount' => $row->amount,
                'invoice' => $row->invoice?->number,
            ], companyId: $company->id);
        }

        if ($remote->status->isCollected() && $row->payment_id === null) {
            $this->record($company, $row);
        }

        if ($remote->status->isProblem()) {
            $this->problem($company, $row, $failureReason);
        }

        return $row->refresh();
    }

    /** Our row for the payment, created (with its invoice) the first time we see it. */
    private function row(Company $company, GcPayment $remote): GoCardlessPayment
    {
        $existing = GoCardlessPayment::withoutCompanyScope()->where('gc_payment_id', $remote->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $chargeDate = $remote->chargeDate !== null ? BillingDates::date($remote->chargeDate) : null;
        $kind = $remote->subscriptionId === null && ($remote->metadata['kind'] ?? null) === PaymentKind::SetupFee->value ? PaymentKind::SetupFee : PaymentKind::Subscription;
        $invoice = null;

        if (! $remote->status->isDead()) {
            $invoice = isset($remote->metadata['invoice_id'])
                ? Invoice::withoutCompanyScope()->where('company_id', $company->id)->find($remote->metadata['invoice_id'])
                : ($remote->subscriptionId !== null ? $this->invoices->forPayment($company, $chargeDate) : null);
        }

        $row = new GoCardlessPayment([
            'gc_payment_id' => $remote->id,
            'gc_subscription_id' => $remote->subscriptionId,
            'gc_mandate_id' => $remote->mandateId,
            'kind' => $kind,
            'invoice_id' => $invoice?->id,
            'amount' => Pence::toPounds($remote->amountPence),
            'charge_date' => $chargeDate,
            'status' => $remote->status,
            'description' => $remote->description !== null ? mb_substr($remote->description, 0, 191) : null,
        ]);
        $row->company_id = $company->id;

        try {
            $row->save();
        } catch (UniqueConstraintViolationException) {
            return GoCardlessPayment::withoutCompanyScope()->where('gc_payment_id', $remote->id)->firstOrFail();
        }

        if ($invoice !== null && $invoice->isOpen() && $remote->status->isPending()) {
            try {
                $this->sendInvoice->handle($invoice->refresh(), resent: false);
            } catch (ValidationException) {
                // Nobody to email: staff can send it later.
            }
        }

        return $row;
    }

    private function record(Company $company, GoCardlessPayment $row): void
    {
        $invoice = $row->invoice_id !== null ? Invoice::withoutCompanyScope()->find($row->invoice_id) : null;
        $allocations = $invoice !== null && $invoice->isOpen()
            ? [$invoice->id => Money::compare($row->amount, $invoice->balance) < 0 ? $row->amount : $invoice->balance]
            : null;

        $result = $this->recordPayment->handle($company, new NewPayment(
            method: PaymentMethod::DirectDebit,
            amount: $row->amount,
            receivedAt: CarbonImmutable::now(),
            reference: $row->gc_payment_id,
            notes: $row->kind === PaymentKind::SetupFee ? 'Setup fee collected by GoCardless' : 'Collected by GoCardless',
            allocations: $allocations,
            gateway: 'gocardless',
            gatewayReference: $row->gc_payment_id,
        ));

        $row->forceFill(['payment_id' => $result->payment->id, 'confirmed_at' => $row->confirmed_at ?? CarbonImmutable::now()])->save();
    }

    private function problem(Company $company, GoCardlessPayment $row, ?string $reason): void
    {
        $row->failed_at ??= CarbonImmutable::now();
        $row->failure_reason = $reason !== null ? mb_substr($reason, 0, 500) : $row->failure_reason;
        $row->save();

        $payment = $row->payment_id !== null ? Payment::withoutCompanyScope()->find($row->payment_id) : null;

        if ($payment !== null && $payment->reversed_at === null) {
            $this->reversePayment->handle($payment, 'Direct Debit '.$row->status->label().($reason !== null ? ": {$reason}" : ''));
        }

        $invoice = $row->invoice_id !== null ? Invoice::withoutCompanyScope()->find($row->invoice_id) : null;

        if ($row->failure_notified_at === null && ($invoice === null || $invoice->isOpen())) {
            DB::transaction(function () use ($company, $row) {
                $this->accounts->lock($company);
                $locked = GoCardlessPayment::withoutCompanyScope()->lockForUpdate()->findOrFail($row->id);

                if ($locked->failure_notified_at !== null) {
                    return;
                }

                $locked->failure_notified_at = CarbonImmutable::now();
                $locked->save();
                $this->mailer->failed($company, $locked->load('invoice'));
            });
        }
    }
}
