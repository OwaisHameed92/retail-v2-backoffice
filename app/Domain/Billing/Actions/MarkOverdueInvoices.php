<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Actions\MarkCompanyOverdue;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * billing:run step: issued or partly paid invoices past their due date (London) become overdue, and their
 * company is marked overdue (trial or active only). Idempotent. Returns how many invoices changed.
 */
class MarkOverdueInvoices
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly MarkCompanyOverdue $markCompanyOverdue,
        private readonly RecordAudit $audit,
    ) {}

    /** `$companyId`: only this business (the demo:billing showcase); null = every business (billing:run). */
    public function handle(CarbonImmutable $now, ?string $companyId = null): int
    {
        $today = BillingDates::today($now)->format('Y-m-d');
        $marked = 0;

        $companyIds = Invoice::withoutCompanyScope()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->where('due_date', '<', $today)->whereNotIn('id', self::beingCollected())
            ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
            ->distinct()->pluck('company_id');

        foreach ($companyIds as $companyId) {
            $company = Company::withTrashed()->find($companyId);

            if ($company === null) {
                continue;
            }

            $numbers = DB::transaction(function () use ($company, $today, $now) {
                $this->accounts->lock($company);
                $numbers = [];

                $invoices = Invoice::withoutCompanyScope()->where('company_id', $company->id)
                    ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
                    ->where('due_date', '<', $today)->whereNotIn('id', self::beingCollected())->lockForUpdate()->get();

                foreach ($invoices as $invoice) {
                    $before = $invoice->status->value;
                    $invoice->status = InvoiceStatus::Overdue;
                    $invoice->overdue_at = $now;
                    $invoice->save();
                    $numbers[] = $invoice->number;

                    $this->audit->handle('invoice.overdue', $invoice, ['status' => $before], ['status' => InvoiceStatus::Overdue->value], [
                        'number' => $invoice->number,
                        'due_date' => $invoice->due_date?->format('Y-m-d'),
                        'balance' => $invoice->balance,
                    ]);
                }

                return $numbers;
            });

            if ($numbers !== []) {
                $marked += count($numbers);
                $this->markCompanyOverdue->handle($company, 'Invoice '.$numbers[0].' is overdue');
            }
        }

        return $marked;
    }

    /**
     * Invoices a Direct Debit payment is still collecting (submitted to the bank, or collected and not recorded
     * yet): GoCardless confirms a few working days after the charge date, which is the due date, so these are not
     * late. If the payment fails the invoice becomes overdue at the next run.
     *
     * @return Builder<GoCardlessPayment>
     */
    private static function beingCollected(): Builder
    {
        return GoCardlessPayment::withoutCompanyScope()->select('invoice_id')->whereNotNull('invoice_id')
            ->where(fn (Builder $q) => $q->whereIn('status', PaymentStatus::pendingValues())
                ->orWhere(fn (Builder $q) => $q->whereIn('status', [PaymentStatus::Confirmed->value, PaymentStatus::PaidOut->value])->whereNull('payment_id')));
    }
}
