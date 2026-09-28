<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Data\InvoicePlan;
use App\Domain\Billing\Data\NewInvoice;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Support\Actor;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\BillingPeriod;
use App\Domain\Billing\Support\InvoiceLineBuilder;
use App\Domain\Billing\Support\InvoiceMaths;
use App\Domain\Billing\Support\Vat;
use App\Domain\Licensing\Actions\RenewCompanyLicences;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a company's invoice for one period: one line per live licence of an active till at its plan's price
 * for the billing cycle (see InvoiceLineBuilder), VAT per the settings. Leaves a draft, or issues it straight
 * away. Refuses a period another invoice already covers unless asked to allow it.
 */
class GenerateInvoice
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly IssueInvoice $issueInvoice,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Company $company, NewInvoice $input = new NewInvoice): Invoice
    {
        $invoice = DB::transaction(function () use ($company, $input) {
            $this->accounts->lock($company);
            $plan = $this->plan($company, $input, CarbonImmutable::now());

            if ($plan->lines === []) {
                throw ValidationException::withMessages(['status' => "{$company->name} has no active tills with a licence to invoice for ".BillingDates::range($plan->periodStart, $plan->periodEnd).'.']);
            }

            if ($plan->overlapsNumber !== null && ! $input->allowOverlap) {
                throw ValidationException::withMessages(['period_start' => "{$plan->overlapsNumber} already covers part of ".BillingDates::range($plan->periodStart, $plan->periodEnd).'. Void it first, or confirm an extra invoice for the same period.']);
            }

            $invoice = new Invoice([
                'status' => InvoiceStatus::Draft,
                'cycle' => $plan->cycle,
                'period_start' => $plan->periodStart,
                'period_end' => $plan->periodEnd,
                'vat_rate' => $plan->vatRate,
                'subtotal' => $plan->totals['subtotal'],
                'vat_total' => $plan->totals['vat_total'],
                'total' => $plan->totals['total'],
                'balance' => $plan->totals['total'],
                'notes' => self::clean($input->notes),
                'prorated' => $plan->prorate,
                'auto_generated' => $input->auto,
                'created_by_admin_id' => $input->auto ? null : Actor::adminId(),
            ]);
            $invoice->company_id = $company->id;
            $invoice->save();

            foreach ($plan->lines as $line) {
                $row = new InvoiceLine($line);
                $row->company_id = $company->id;
                $row->invoice_id = $invoice->id;
                $row->save();
            }

            $this->audit->handle('invoice.created', $invoice, null, [
                'period_start' => $plan->periodStart->format('Y-m-d'),
                'period_end' => $plan->periodEnd->format('Y-m-d'),
                'total' => $plan->totals['total'],
            ], [
                'lines' => count($plan->lines),
                'auto' => $input->auto,
                'overlaps' => $plan->overlapsNumber,
            ]);

            return $invoice;
        });

        return $input->issue ? $this->issueInvoice->handle($invoice, $input->send, $input->dueDate) : $invoice->refresh();
    }

    /**
     * What handle() would create (no writes): period, lines, totals, overlap.
     */
    public function plan(Company $company, NewInvoice $input, CarbonImmutable $now): InvoicePlan
    {
        if ($company->trashed() || $company->isCancelled()) {
            throw ValidationException::withMessages(['status' => "{$company->name} is cancelled, so it cannot be invoiced."]);
        }

        $account = $this->accounts->for($company);
        $cycle = $input->cycle ?? $account->cycle;
        $prorate = $input->prorate ?? (bool) config('billing.generate.prorate', false);
        $licences = RenewCompanyLicences::renewable($company)->get();
        $start = $input->periodStart ?? BillingPeriod::nextStart($company, $now, $licences);
        $end = $cycle->periodEnd($start);
        $vatRate = Vat::rateFor($account);

        $lines = InvoiceLineBuilder::build($licences, $start, $end, $cycle, $vatRate, $prorate);

        $overlap = Invoice::withoutCompanyScope()->where('company_id', $company->id)->notVoid()->forPeriods()
            ->where('period_start', '<=', $end->format('Y-m-d'))
            ->where('period_end', '>=', $start->format('Y-m-d'))
            ->orderBy('period_start')
            ->first();

        return new InvoicePlan(
            cycle: $cycle,
            periodStart: $start,
            periodEnd: $end,
            vatRate: $vatRate,
            prorate: $prorate,
            lines: $lines,
            totals: InvoiceMaths::totals($lines),
            overlapsNumber: $overlap?->displayNumber() === 'Draft' ? 'A draft invoice' : $overlap?->number,
        );
    }

    private static function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : mb_substr($text, 0, 2000);
    }
}
