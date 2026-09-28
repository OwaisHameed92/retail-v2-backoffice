<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Data\NewPayment;
use App\Domain\Billing\Data\UpfrontPayment;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Enums\SetupFeeMethod;
use App\Domain\Billing\GoCardless\Actions\ChargeSetupFee;
use App\Domain\Billing\GoCardless\Support\SetupFee;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records what a business paid upfront (module 1.13), at onboarding or later from the Billing tab: the setup fee
 * (plan fee or the amount staff enter, net) becomes a setup fee invoice (plus VAT per the settings), paid at once
 * by the cash or bank transfer payment, and the paid invoice is emailed as the receipt. £0 records that nothing
 * was due (no invoice). Once per business; the setup fee is then never charged again (not by Direct Debit
 * either). A paid setup fee does not end a trial.
 */
class RecordUpfrontPayment
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly ChargeSetupFee $chargeSetupFee,
        private readonly RecordPayment $recordPayment,
        private readonly SendInvoice $sendInvoice,
        private readonly RecordAudit $audit,
    ) {}

    /** @throws ValidationException */
    public function handle(Company $company, UpfrontPayment $input): BillingAccount
    {
        if (! in_array($input->method, [PaymentMethod::Cash, PaymentMethod::BankTransfer], true)) {
            throw ValidationException::withMessages(['upfront_method' => 'Choose cash or bank transfer.']);
        }

        if ($input->setupFee !== null && Money::isNegative($input->setupFee)) {
            throw ValidationException::withMessages(['upfront_amount' => 'The amount cannot be below £0.00.']);
        }

        $net = DB::transaction(function () use ($company, $input) {
            $account = $this->accounts->lock($company);

            if ($account->upfront_recorded_at !== null || $account->setup_fee_invoiced_at !== null) {
                throw ValidationException::withMessages(['upfront_amount' => "The upfront payment for {$company->name} is already recorded (or its setup fee invoiced)."]);
            }

            $account->setup_fee_override = $input->setupFee === null ? null : Money::normalise($input->setupFee);
            $account->setup_fee_method = SetupFeeMethod::Manual;
            $account->setup_fee_instalments = 1;
            $net = SetupFee::net($company, $account);

            if (Money::isZero($net)) {
                $this->stamp($company, $account, '0.00', $input->method, null);
            } else {
                $account->save();
            }

            return $net;
        });

        if (Money::isZero($net)) {
            return $this->accounts->for($company);
        }

        $invoice = $this->chargeSetupFee->handle($company, send: false)[0];

        $this->recordPayment->handle($company, new NewPayment(
            method: $input->method,
            amount: $invoice->total,
            receivedAt: $input->receivedAt ?? CarbonImmutable::now(),
            reference: $input->reference ?? 'Upfront payment',
            notes: 'Upfront payment at onboarding',
            allocations: [$invoice->id => $invoice->total],
        ));

        DB::transaction(fn () => $this->stamp($company, $this->accounts->lock($company), $invoice->total, $input->method, $invoice->number));

        try {
            $this->sendInvoice->handle($invoice->refresh(), resent: false);
        } catch (ValidationException) {
            // Nobody to email yet; staff can send it from the invoice.
        }

        return $this->accounts->for($company);
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
