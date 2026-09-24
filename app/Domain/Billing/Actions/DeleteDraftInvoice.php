<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a draft (it has no number, so nothing is lost from the sequence). Issued invoices are voided instead.
 */
class DeleteDraftInvoice
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(Invoice $invoice): void
    {
        /** @var Company $company */
        $company = $invoice->company()->firstOrFail();

        DB::transaction(function () use ($invoice, $company) {
            $this->accounts->lock($company);
            $invoice = Invoice::withoutCompanyScope()->lockForUpdate()->findOrFail($invoice->id);

            if (! $invoice->isDraft()) {
                throw ValidationException::withMessages(['status' => "{$invoice->number} is issued, so it cannot be deleted. Void it instead."]);
            }

            $this->audit->handle('invoice.deleted', $invoice, [
                'period_start' => $invoice->period_start->format('Y-m-d'),
                'period_end' => $invoice->period_end->format('Y-m-d'),
                'total' => $invoice->total,
            ], null, companyId: $invoice->company_id);

            InvoiceLine::withoutCompanyScope()->where('invoice_id', $invoice->id)->delete();
            $invoice->delete();
        });
    }
}
