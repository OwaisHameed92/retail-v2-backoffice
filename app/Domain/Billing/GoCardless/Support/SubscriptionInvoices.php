<?php

namespace App\Domain\Billing\GoCardless\Support;

use App\Domain\Billing\Actions\GenerateInvoice;
use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Data\NewInvoice;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\BillingPeriod;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The invoice a GoCardless subscription payment collects, so every payment maps to one of our invoices:
 *
 * 1. the oldest open period invoice no other Direct Debit payment is collecting (a failed month is collected by
 *    the next payment before a new period is invoiced);
 * 2. else a draft for the next period (billing:run may have made one before the subscription existed), issued;
 * 3. else a new invoice for the next unpaid period (after the tills' paid time and after any period already
 *    invoiced), issued with the collection date as its due date.
 *
 * Issued without email: the caller emails it once the payment is linked (the email then says "collected by Direct
 * Debit on …"). Null when there is nothing to invoice (no live tills).
 */
final class SubscriptionInvoices
{
    public function __construct(
        private readonly GenerateInvoice $generateInvoice,
        private readonly IssueInvoice $issueInvoice,
    ) {}

    public function forPayment(Company $company, ?CarbonImmutable $chargeDate): ?Invoice
    {
        $taken = GoCardlessPayment::withoutCompanyScope()->where('company_id', $company->id)->whereNotNull('invoice_id')
            ->whereNotIn('status', [PaymentStatus::Failed->value, PaymentStatus::ChargedBack->value, PaymentStatus::Cancelled->value, PaymentStatus::CustomerApprovalDenied->value])
            ->pluck('invoice_id')->all();

        $open = Invoice::withoutCompanyScope()->where('company_id', $company->id)->forPeriods()->open()
            ->whereNotIn('id', $taken)->orderBy('period_start')->orderBy('sequence')->first();

        if ($open !== null) {
            return $open;
        }

        $start = $this->nextStart($company);
        $draft = Invoice::withoutCompanyScope()->where('company_id', $company->id)->forPeriods()
            ->where('status', InvoiceStatus::Draft->value)->where('period_start', $start->format('Y-m-d'))->first();

        try {
            if ($draft !== null) {
                return $this->issueInvoice->handle($draft, send: false, dueDate: $chargeDate);
            }

            return $this->generateInvoice->handle($company, new NewInvoice(
                periodStart: $start,
                issue: true,
                auto: true,
                dueDate: $chargeDate,
                send: false,
                allowOverlap: true,
            ));
        } catch (ValidationException $exception) {
            Log::warning('Direct Debit payment without an invoice', ['company_id' => $company->id, 'errors' => $exception->errors()]);

            return null;
        }
    }

    /** The day after the tills' paid time, or after the last period already invoiced, whichever is later. */
    private function nextStart(Company $company): CarbonImmutable
    {
        $start = BillingPeriod::nextStart($company, CarbonImmutable::now());
        $last = Invoice::withoutCompanyScope()->where('company_id', $company->id)->forPeriods()->notVoid()
            ->where('status', '!=', InvoiceStatus::Draft->value)->max('period_end');

        if (is_string($last) && $last !== '') {
            $after = BillingDates::date(substr($last, 0, 10))->addDay();
            $start = $after->greaterThan($start) ? $after : $start;
        }

        return $start;
    }
}
