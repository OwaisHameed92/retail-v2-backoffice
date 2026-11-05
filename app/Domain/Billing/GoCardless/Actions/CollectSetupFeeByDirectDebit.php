<?php

namespace App\Domain\Billing\GoCardless\Actions;

use App\Domain\Billing\Actions\SendInvoice;
use App\Domain\Billing\GoCardless\Contracts\GoCardlessClient;
use App\Domain\Billing\GoCardless\Enums\PaymentKind;
use App\Domain\Billing\GoCardless\GoCardlessException;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\GoCardless\Support\Pence;
use App\Domain\Billing\GoCardless\Support\SetupFeeByDirectDebit;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Demo\Support\DemoBusinesses;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\BusinessFailures;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Owner 2026-11-05: collects a business's setup fee instalments by Direct Debit, only when staff switched
 * "Setup fee: By Direct Debit" on (SetupFeeByDirectDebit::on) and the mandate works. For each unpaid setup fee
 * invoice due within `setup_fee_lead_days` (overdue ones too) that has no GoCardless payment standing, one one-off
 * payment for exactly its balance, charged on its due date (or the first day GoCardless allows), with the invoice id in
 * its metadata (ApplyGoCardlessPayment records it on that invoice when confirmed). Never for an instalment paid (by
 * hand or otherwise), never two for one invoice (a GoCardless idempotency key per invoice and attempt, and the
 * unique payment id), never for a demo business. Called by ChargeSetupFee, ApplyMandate (a new mandate), Payment
 * settings (switched on) and billing:run every day (later instalments). Safe to repeat. Returns the payments made.
 */
class CollectSetupFeeByDirectDebit
{
    public const TASK = 'billing:run setup-fee-direct-debit';

    public function __construct(
        private readonly GoCardlessClient $client,
        private readonly BillingAccounts $accounts,
        private readonly SendInvoice $sendInvoice,
        private readonly RecordAudit $audit,
        private readonly BusinessFailures $failures,
    ) {}

    /**
     * @param  bool  $email  email every open instalment again with its Direct Debit day (the customer was told
     *                       otherwise before: the option was just switched on, or the mandate just arrived)
     * @return list<GoCardlessPayment>
     */
    public function handle(Company $company, bool $email = false, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $account = $this->accounts->for($company);

        if (! SetupFeeByDirectDebit::on($account) || ! $account->hasUsableMandate() || ! $this->client->enabled()
            || DemoBusinesses::isDemo($company) || $company->isCancelled() || $company->trashed()) {
            return [];
        }

        $today = BillingDates::today($now);
        $invoices = SetupFeeByDirectDebit::invoices($company)->filter(fn (Invoice $invoice) => $invoice->isOpen());
        $due = $invoices->filter(fn (Invoice $invoice) => ! Money::isZero($invoice->balance)
            && ($invoice->due_date === null || $invoice->due_date->lessThanOrEqualTo($today->addDays(SetupFeeByDirectDebit::leadDays())))
            && SetupFeeByDirectDebit::paymentFor($invoice) === null);

        $created = [];
        $earliest = $due->isEmpty() ? null : $this->earliest($account);
        $count = SetupFeeByDirectDebit::invoices($company)->count();

        foreach ($due as $invoice) {
            $row = $this->collect($company, $account, $invoice, $earliest, $today, $count);

            if ($row !== null) {
                $created[] = $row;
            }
        }

        if ($email) {
            foreach ($invoices as $invoice) {
                $this->send($invoice);
            }
        }

        return $created;
    }

    /**
     * billing:run: every business with the option on and a working mandate (later instalments come due). One
     * business's error is reported and the others still run. Returns how many payments were made.
     */
    public function daily(CarbonImmutable $now): int
    {
        $made = 0;
        $accounts = BillingAccount::withoutCompanyScope()->where('setup_fee_by_direct_debit', true)
            ->whereNotNull('gc_mandate_id')->whereNotNull('setup_fee_invoiced_at')->orderBy('company_id')->get();

        foreach ($accounts as $account) {
            $made += (int) $this->failures->attempt(self::TASK, $account->company_id, function () use ($account, $now) {
                $company = Company::query()->find($account->company_id);

                return $company === null ? 0 : count($this->handle($company, now: $now));
            }, 0);
        }

        return $made;
    }

    private function collect(Company $company, BillingAccount $account, Invoice $invoice, ?string $earliest, CarbonImmutable $today, int $count): ?GoCardlessPayment
    {
        $part = $count > 1 ? $this->partNumber($company, $invoice) : null;
        $attempt = GoCardlessPayment::withoutCompanyScope()->where('invoice_id', $invoice->id)->count() + 1;
        $chargeDate = SetupFeeByDirectDebit::chargeDate($invoice, $earliest, $today);

        try {
            $remote = $this->client->createPayment(
                mandateId: (string) $account->gc_mandate_id,
                amountPence: Pence::fromPounds($invoice->balance),
                chargeDate: $chargeDate,
                description: $part !== null ? "Switch & Save setup fee {$part}/{$count}" : 'Switch & Save setup fee',
                metadata: ['company_id' => $company->id, 'invoice_id' => $invoice->id, 'kind' => PaymentKind::SetupFee->value],
                idempotencyKey: 'setup-fee:'.$invoice->id.':'.$attempt,
            );
        } catch (GoCardlessException $exception) {
            // The invoice stays issued (billing:run tries again tomorrow; staff may record a payment by hand).
            $this->audit->handle('billing.dd_payment_create_failed', $invoice, null, null, ['error' => $exception->getMessage(), 'kind' => 'setupFee'], companyId: $company->id);

            return null;
        }

        $row = new GoCardlessPayment([
            'gc_payment_id' => $remote->id,
            'gc_mandate_id' => $remote->mandateId,
            'kind' => PaymentKind::SetupFee,
            'invoice_id' => $invoice->id,
            'amount' => Pence::toPounds($remote->amountPence),
            'charge_date' => $remote->chargeDate !== null ? BillingDates::date($remote->chargeDate) : null,
            'status' => $remote->status,
            'description' => $remote->description !== null ? mb_substr($remote->description, 0, 191) : null,
            'instalment' => $part,
            'instalments' => $part !== null ? $count : null,
        ]);
        $row->company_id = $company->id;

        try {
            $row->save();
        } catch (UniqueConstraintViolationException) {
            return null; // the same GoCardless payment is already ours (a webhook or a parallel run got there first)
        }

        $this->audit->handle('billing.dd_setup_fee_payment_created', $row, null, ['status' => $remote->status->value], [
            'gc_payment' => $remote->id,
            'invoice' => $invoice->number,
            'amount' => $row->amount,
            'charge_date' => $row->charge_date?->format('Y-m-d'),
        ], companyId: $company->id);

        return $row;
    }

    /** "2" of "Setup fee, instalment 2 of 3": the invoice's place among the business's setup fee invoices. */
    private function partNumber(Company $company, Invoice $invoice): int
    {
        $index = SetupFeeByDirectDebit::invoices($company)->search(fn (Invoice $other) => $other->id === $invoice->id);

        return $index === false ? 1 : $index + 1;
    }

    private function earliest(BillingAccount $account): ?string
    {
        try {
            return $this->client->mandate((string) $account->gc_mandate_id)->nextPossibleChargeDate;
        } catch (GoCardlessException) {
            return null;
        }
    }

    private function send(Invoice $invoice): void
    {
        try {
            $this->sendInvoice->handle($invoice->refresh(), resent: true);
        } catch (ValidationException) {
            // Nobody to email: staff can send it later.
        }
    }
}
