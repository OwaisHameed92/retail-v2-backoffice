<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Data\NewPayment;
use App\Domain\Billing\Data\UpfrontPayment;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Enums\SetupFeeMethod;
use App\Domain\Billing\GoCardless\Actions\ChargeSetupFee;
use App\Domain\Billing\GoCardless\Support\SetupFee;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records a setup fee payment. "Setup fee" and "upfront payment" are the same thing (owner, 2026-10-05): the one-time
 * amount paid at the start, always by hand (cash, card on our card machine, or bank transfer), recorded by an admin
 * at onboarding (wizard, trial approval) or later from the Billing tab, never collected by Direct Debit.
 *
 * - Not invoiced yet: the setup fee (plan fee, or the amount staff enter, net) is invoiced (one invoice, or one per
 *   instalment a month apart) and this payment pays the first one. £0 records "nothing to pay" without an invoice.
 * - Already invoiced (instalments): this payment pays the oldest unpaid setup fee invoice.
 *
 * The paid invoice is emailed as the receipt (with any later instalment invoices). The first payment is stamped on
 * the account (`upfront_*`). Paying it unlocks what the setup fee holds back (RecordPayment → ApplySetupFeeTerms).
 */
class RecordUpfrontPayment
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly ChargeSetupFee $chargeSetupFee,
        private readonly RecordPayment $recordPayment,
        private readonly SendInvoice $sendInvoice,
        private readonly ApplySetupFeeTerms $setupFeeTerms,
        private readonly RecordAudit $audit,
    ) {}

    /** @throws ValidationException */
    public function handle(Company $company, UpfrontPayment $input): BillingAccount
    {
        if (! in_array($input->method, PaymentMethod::setupFee(), true)) {
            throw ValidationException::withMessages(['upfront_method' => 'Choose cash, card or bank transfer.']);
        }

        if ($input->setupFee !== null && Money::isNegative($input->setupFee)) {
            throw ValidationException::withMessages(['upfront_amount' => 'The amount cannot be below £0.00.']);
        }

        $net = DB::transaction(function () use ($company, $input) {
            $account = $this->accounts->lock($company);

            if ($account->setup_fee_invoiced_at !== null) {
                return null; // instalments: pay the next one below (the amount is fixed by the invoices)
            }

            if ($account->upfront_recorded_at !== null) {
                throw ValidationException::withMessages(['upfront_amount' => "The setup fee for {$company->name} is already recorded."]);
            }

            if ($input->setupFee !== null) {
                $account->setup_fee_override = Money::normalise($input->setupFee);
            }

            $account->setup_fee_method = SetupFeeMethod::Manual;
            $net = SetupFee::net($company, $account);

            if (Money::isZero($net)) {
                $this->stamp($company, $account, '0.00', $input->method, null);
            } else {
                $account->save();
            }

            return $net;
        });

        if ($net !== null && Money::isZero($net)) {
            $this->setupFeeTerms->handle($company, justPaid: true);

            return $this->accounts->for($company);
        }

        $created = $net !== null ? $this->chargeSetupFee->handle($company, send: false) : [];
        $invoice = $this->nextUnpaid($company);

        if ($invoice === null) {
            throw ValidationException::withMessages(['upfront_amount' => "The setup fee for {$company->name} is already paid."]);
        }

        $this->recordPayment->handle($company, new NewPayment(
            method: $input->method,
            amount: $invoice->balance,
            receivedAt: $input->receivedAt ?? CarbonImmutable::now(),
            reference: $input->reference ?? 'Setup fee',
            notes: 'Setup fee (upfront) paid by '.mb_strtolower($input->method->label()),
            allocations: [$invoice->id => $invoice->balance],
        ));

        DB::transaction(function () use ($company, $invoice, $input) {
            $account = $this->accounts->lock($company);

            if ($account->upfront_recorded_at === null) {
                $this->stamp($company, $account, $invoice->total, $input->method, $invoice->number);
            }
        });

        foreach ([$invoice, ...array_filter($created, fn (Invoice $other) => $other->id !== $invoice->id)] as $toSend) {
            try {
                $this->sendInvoice->handle($toSend->refresh(), resent: false);
            } catch (ValidationException) {
                // Nobody to email yet; staff can send it from the invoice.
            }
        }

        return $this->accounts->for($company);
    }

    private function nextUnpaid(Company $company): ?Invoice
    {
        return Invoice::withoutCompanyScope()->where('company_id', $company->id)
            ->where('kind', InvoiceKind::SetupFee->value)->open()
            ->orderBy('due_date')->orderBy('sequence')->first();
    }

    private function stamp(Company $company, BillingAccount $account, string $gross, PaymentMethod $method, ?string $invoiceNumber): void
    {
        $account->upfront_amount = $gross;
        $account->upfront_method = $method;
        $account->upfront_recorded_at = CarbonImmutable::now();
        $account->setup_fee_invoiced_at ??= CarbonImmutable::now();
        $account->save();

        $this->audit->handle('billing.upfront_recorded', $account, null, [
            'amount' => $gross,
            'method' => $method->value,
        ], ['amount_label' => BillingFormat::money($gross), 'invoice' => $invoiceNumber], companyId: $company->id);
    }
}
