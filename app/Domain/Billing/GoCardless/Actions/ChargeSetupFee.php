<?php

namespace App\Domain\Billing\GoCardless\Actions;

use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Actions\SendInvoice;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\SetupFeeMethod;
use App\Domain\Billing\GoCardless\Contracts\GoCardlessClient;
use App\Domain\Billing\GoCardless\Enums\PaymentKind;
use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\GoCardless\Support\Pence;
use App\Domain\Billing\GoCardless\Support\SetupFee;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Support\Actor;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\InvoiceMaths;
use App\Domain\Billing\Support\Vat;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Invoices the setup fee, once per company: one invoice ("Setup fee"), or one per instalment ("Setup fee,
 * instalment 2 of 3") due a month apart. By Direct Debit each invoice gets its own GoCardless payment on its due
 * date (so every GoCardless payment maps to one invoice) and the invoice email says it is collected; paid by hand
 * they are emailed as usual and paid through Record payment (cash or bank transfer).
 */
class ChargeSetupFee
{
    public function __construct(
        private readonly GoCardlessClient $client,
        private readonly BillingAccounts $accounts,
        private readonly IssueInvoice $issueInvoice,
        private readonly SendInvoice $sendInvoice,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @return list<Invoice>
     *
     * @throws ValidationException
     */
    public function handle(Company $company, bool $send = true): array
    {
        $account = $this->accounts->for($company);
        $byDirectDebit = $account->isDirectDebit() && $account->setup_fee_method === SetupFeeMethod::DirectDebit;

        if ($account->setup_fee_invoiced_at !== null) {
            throw ValidationException::withMessages(['setup_fee' => "The setup fee for {$company->name} is already invoiced."]);
        }

        if ($byDirectDebit && ! $account->hasUsableMandate()) {
            throw ValidationException::withMessages(['setup_fee' => 'The setup fee is collected by Direct Debit once the customer has set it up. Send the setup email first.']);
        }

        if ($company->isCancelled()) {
            throw ValidationException::withMessages(['setup_fee' => "{$company->name} is cancelled."]);
        }

        $schedule = SetupFee::schedule($company, $account);

        if ($schedule === []) {
            throw ValidationException::withMessages(['setup_fee' => "{$company->name} has no setup fee to charge."]);
        }

        $first = $byDirectDebit ? $this->firstChargeDate($account) : BillingDates::today();
        $drafts = $this->createDrafts($company, $schedule, $first);
        $invoices = [];

        foreach ($drafts as $i => $draft) {
            $due = $first->addMonthsNoOverflow($i);

            if (! $byDirectDebit) {
                $invoices[] = $this->issueInvoice->handle($draft, send: $send, dueDate: $i === 0 ? null : $due);

                continue;
            }

            $invoice = $this->issueInvoice->handle($draft, send: false, dueDate: $due);

            if ($invoice->isOpen()) {
                $this->collect($company, $account, $invoice, $due, $i + 1, count($drafts));
            }

            $this->send($invoice);
            $invoices[] = $invoice->refresh();
        }

        return $invoices;
    }

    /**
     * @param  list<array{net: string, vat: string, gross: string}>  $schedule
     * @return list<Invoice>
     */
    private function createDrafts(Company $company, array $schedule, CarbonImmutable $first): array
    {
        return DB::transaction(function () use ($company, $schedule, $first) {
            $account = $this->accounts->lock($company);

            if ($account->setup_fee_invoiced_at !== null) {
                throw ValidationException::withMessages(['setup_fee' => "The setup fee for {$company->name} is already invoiced."]);
            }

            $count = count($schedule);
            $drafts = [];

            foreach ($schedule as $i => $amounts) {
                $day = $first->addMonthsNoOverflow($i);
                $label = $count === 1 ? 'Setup fee' : 'Setup fee, instalment '.($i + 1)." of {$count}";
                $totals = InvoiceMaths::totals([$amounts]);

                $invoice = new Invoice([
                    'status' => InvoiceStatus::Draft,
                    'kind' => InvoiceKind::SetupFee,
                    'cycle' => $account->cycle,
                    'period_start' => $day,
                    'period_end' => $day,
                    'vat_rate' => Vat::rateFor($account),
                    'subtotal' => $totals['subtotal'],
                    'vat_total' => $totals['vat_total'],
                    'total' => $totals['total'],
                    'balance' => $totals['total'],
                    'created_by_admin_id' => Actor::adminId(),
                ]);
                $invoice->company_id = $company->id;
                $invoice->save();

                $line = new InvoiceLine([
                    'position' => 1,
                    'description' => $label.' · '.$company->name,
                    'quantity' => '1.0000',
                    'unit_price' => $amounts['net'],
                    'net' => $amounts['net'],
                    'vat' => $amounts['vat'],
                    'gross' => $amounts['gross'],
                ]);
                $line->company_id = $company->id;
                $line->invoice_id = $invoice->id;
                $line->save();

                $drafts[] = $invoice;
            }

            $account->setup_fee_invoiced_at = CarbonImmutable::now();
            $account->save();

            $this->audit->handle('billing.setup_fee_invoiced', $account, null, [
                'net' => SetupFee::net($company, $account),
                'instalments' => $count,
            ], ['method' => $account->setup_fee_method->value, 'mode' => $account->billing_mode->value], companyId: $company->id);

            return $drafts;
        });
    }

    private function collect(Company $company, BillingAccount $account, Invoice $invoice, CarbonImmutable $due, int $number, int $of): void
    {
        try {
            $payment = $this->client->createPayment(
                mandateId: (string) $account->gc_mandate_id,
                amountPence: Pence::fromPounds($invoice->balance),
                chargeDate: $due->format('Y-m-d'),
                description: $of === 1 ? 'Switch & Save setup fee' : "Switch & Save setup fee {$number}/{$of}",
                metadata: ['company_id' => $company->id, 'invoice_id' => $invoice->id, 'kind' => PaymentKind::SetupFee->value],
                idempotencyKey: 'setup-fee:'.$invoice->id,
            );
        } catch (GoCardlessException $exception) {
            // The invoice stays issued and can be paid by hand; staff see why in the audit log.
            $this->audit->handle('billing.dd_payment_create_failed', $invoice, null, null, ['error' => $exception->getMessage()]);

            return;
        }

        $row = new GoCardlessPayment([
            'gc_payment_id' => $payment->id,
            'gc_mandate_id' => $payment->mandateId,
            'kind' => PaymentKind::SetupFee,
            'invoice_id' => $invoice->id,
            'amount' => Pence::toPounds($payment->amountPence),
            'charge_date' => $payment->chargeDate !== null ? BillingDates::date($payment->chargeDate) : $due,
            'status' => $payment->status,
            'description' => $payment->description,
            'instalment' => $of > 1 ? $number : null,
            'instalments' => $of > 1 ? $of : null,
        ]);
        $row->company_id = $company->id;
        $row->save();
    }

    private function send(Invoice $invoice): void
    {
        try {
            $this->sendInvoice->handle($invoice->refresh(), resent: false);
        } catch (ValidationException) {
            // Nobody to email yet; staff can send it later.
        }
    }

    private function firstChargeDate(BillingAccount $account): CarbonImmutable
    {
        $today = BillingDates::today();

        try {
            $earliest = $this->client->mandate((string) $account->gc_mandate_id)->nextPossibleChargeDate;
        } catch (GoCardlessException) {
            $earliest = null;
        }

        return $earliest !== null ? BillingDates::date($earliest)->max($today) : $today->addWeekdays(3);
    }
}
