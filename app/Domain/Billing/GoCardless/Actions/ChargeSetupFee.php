<?php

namespace App\Domain\Billing\GoCardless\Actions;

use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\GoCardless\Support\SetupFee;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Support\Actor;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\InvoiceMaths;
use App\Domain\Billing\Support\SetupFeeTills;
use App\Domain\Billing\Support\Vat;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Invoices the setup fee (the one-time "upfront" amount), once per company: one invoice ("Setup fee"), or one per
 * instalment ("Setup fee, instalment 2 of 3") due a month apart. Owner rule (2026-10-05): the setup fee is ALWAYS
 * paid by hand (cash, card or bank transfer, recorded by an admin) and NEVER collected by Direct Debit, whatever the
 * billing mode or the legacy `setup_fee_method` value. The invoices are emailed as usual (unless `$send` is false)
 * and paid through Record payment / Record setup fee payment.
 */
class ChargeSetupFee
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly IssueInvoice $issueInvoice,
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

        if ($account->setup_fee_invoiced_at !== null) {
            throw ValidationException::withMessages(['setup_fee' => "The setup fee for {$company->name} is already invoiced."]);
        }

        if ($company->isCancelled()) {
            throw ValidationException::withMessages(['setup_fee' => "{$company->name} is cancelled."]);
        }

        $schedule = SetupFee::schedule($company, $account);

        if ($schedule === []) {
            throw ValidationException::withMessages(['setup_fee' => "{$company->name} has no setup fee to charge."]);
        }

        $first = BillingDates::today();
        $invoices = [];

        foreach ($this->createDrafts($company, $schedule, $first) as $i => $draft) {
            $invoices[] = $this->issueInvoice->handle($draft, send: $send, dueDate: $i === 0 ? null : $first->addMonthsNoOverflow($i));
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
            // P11: this setup fee covers the tills the business has now (per till: it was worked out for them), and
            // a per-till fee is kept as the business's amount, so tills added later never change what it shows.
            if ($account->setup_fee_override === null && SetupFeeTills::perTill($company)) {
                $account->setup_fee_override = SetupFee::net($company, $account);
            }
            SetupFeeTills::coverAll($company, $account);
            $account->save();

            $this->audit->handle('billing.setup_fee_invoiced', $account, null, [
                'net' => SetupFee::net($company, $account),
                'instalments' => $count,
            ], ['method' => 'manual', 'mode' => $account->billing_mode->value], companyId: $company->id);

            return $drafts;
        });
    }
}
