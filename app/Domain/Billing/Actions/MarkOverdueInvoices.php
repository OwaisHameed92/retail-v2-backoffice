<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Actions\MarkCompanyOverdue;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
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

    public function handle(CarbonImmutable $now): int
    {
        $today = BillingDates::today($now)->format('Y-m-d');
        $marked = 0;

        $companyIds = Invoice::withoutCompanyScope()
            ->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value])
            ->where('due_date', '<', $today)
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
                    ->where('due_date', '<', $today)->lockForUpdate()->get();

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
}
