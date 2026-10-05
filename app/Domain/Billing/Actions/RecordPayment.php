<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Data\NewPayment;
use App\Domain\Billing\Data\RecordedPayment;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Support\Actor;
use App\Domain\Billing\Support\Allocator;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\DocumentNumbers;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records money received from a company (receipt PAY-000001) and allocates it: to the invoices staff chose, or
 * oldest open invoice first. Whatever is left stays on the company as credit (used on its next invoice). Each
 * invoice that ends up paid is settled: exactly its licences are renewed to the period end, with the "licences
 * renewed" email. When nothing is overdue any more a billing suspension is lifted and the company is active.
 */
class RecordPayment
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly DocumentNumbers $numbers,
        private readonly Allocator $allocator,
        private readonly SettleInvoice $settleInvoice,
        private readonly ReleaseBillingHolds $releaseHolds,
        private readonly ApplySetupFeeTerms $setupFeeTerms,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, NewPayment $input): RecordedPayment
    {
        $amount = Money::normalise($input->amount);

        if (Money::compare($amount, '0') <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount above £0.00.']);
        }

        if ($company->trashed()) {
            throw ValidationException::withMessages(['status' => "{$company->name} is deleted."]);
        }

        $result = DB::transaction(function () use ($company, $input, $amount) {
            $now = CarbonImmutable::now();
            $this->accounts->lock($company);

            if ($input->gateway !== null && $input->gatewayReference !== null) {
                $existing = Payment::withoutCompanyScope()->where('company_id', $company->id)
                    ->where('gateway', $input->gateway)->where('gateway_reference', $input->gatewayReference)->first();

                if ($existing !== null) {
                    return new RecordedPayment($existing, [], 0, $existing->unallocated, false, duplicate: true);
                }
            }

            $invoices = $this->invoicesToPay($company, $input, $amount);

            [$sequence, $number] = $this->numbers->next(DocumentNumbers::PAYMENT);
            $payment = new Payment([
                'number' => $number,
                'sequence' => $sequence,
                'method' => $input->method,
                'amount' => $amount,
                'unallocated' => $amount,
                'received_at' => $input->receivedAt->utc(), // stored as UTC whatever zone the caller used
                'reference' => self::clean($input->reference, 100),
                'notes' => self::clean($input->notes, 2000),
                'received_by_admin_id' => Actor::adminId(),
                'gateway' => $input->gateway,
                'gateway_reference' => $input->gatewayReference,
            ]);
            $payment->company_id = $company->id;
            $payment->save();

            $paid = [];
            $renewed = 0;
            $allocated = [];

            foreach ($invoices as [$invoice, $share]) {
                $allocation = $this->allocator->allocate($payment, $invoice, $share);

                if ($allocation === null) {
                    continue;
                }

                $allocated[] = ['invoice' => $invoice->number, 'amount' => $allocation->amount];

                if ($invoice->isOpen() && Money::isZero($invoice->balance)) {
                    $renewed += count($this->settleInvoice->handle($invoice, $now));
                    $paid[] = $invoice;
                }
            }

            $this->audit->handle('payment.recorded', $payment, null, [
                'amount' => $amount,
                'method' => $input->method->value,
            ], [
                'number' => $number,
                'amount' => $amount,
                'amount_label' => BillingFormat::money($amount),
                'method' => $input->method->label(),
                'allocations' => $allocated,
                'credit' => $payment->unallocated,
                'reference' => $payment->reference,
            ], companyId: $company->id);

            return new RecordedPayment($payment->refresh(), $paid, $renewed, $payment->unallocated, false);
        });

        if ($result->duplicate) {
            return $result;
        }

        $unsuspended = $this->releaseHolds->handle($company);

        // A setup fee (upfront) invoice paid: unlock what it holds back (setup-only licence, the Direct Debit).
        if (array_filter($result->paidInvoices, fn (Invoice $invoice) => $invoice->kind === InvoiceKind::SetupFee) !== []) {
            $this->setupFeeTerms->handle($company, justPaid: true);
        }

        return new RecordedPayment($result->payment, $result->paidInvoices, $result->licencesRenewed, $result->credit, $unsuspended);
    }

    /**
     * The invoices to pay, in order, with the most to put on each (null = as much as possible).
     *
     * @return list<array{0: Invoice, 1: string|null}>
     *
     * @throws ValidationException
     */
    private function invoicesToPay(Company $company, NewPayment $input, string $amount): array
    {
        /** @var Collection<int, Invoice> $open */
        $open = Invoice::withoutCompanyScope()->where('company_id', $company->id)->open()
            ->orderBy('due_date')->orderBy('sequence')->lockForUpdate()->get();

        if ($input->allocations === null) {
            return $open->map(fn (Invoice $invoice) => [$invoice, null])->values()->all();
        }

        $chosen = [];
        $total = '0.00';

        foreach ($input->allocations as $invoiceId => $share) {
            $share = Money::normalise($share);

            if (Money::isZero($share)) {
                continue;
            }

            $invoice = $open->firstWhere('id', $invoiceId);

            if ($invoice === null) {
                throw ValidationException::withMessages(['allocations' => 'One of the chosen invoices is not open for this business any more. Reload and try again.']);
            }

            if (Money::isNegative($share) || Money::compare($share, $invoice->balance) > 0) {
                throw ValidationException::withMessages(['allocations' => 'Put between £0.01 and '.BillingFormat::money($invoice->balance)." on {$invoice->number}."]);
            }

            $total = Money::add($total, $share);
            $chosen[] = [$invoice, $share];
        }

        if (Money::compare($total, $amount) > 0) {
            throw ValidationException::withMessages(['allocations' => 'The amounts put on invoices add up to more than the payment.']);
        }

        return $chosen;
    }

    private static function clean(?string $text, int $max): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
