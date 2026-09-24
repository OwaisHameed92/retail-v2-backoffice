<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Billing\Support\Actor;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels an issued invoice that is not paid: it keeps its number (no gaps) and becomes "void". Money already
 * put on it goes back to the company's credit. Optionally creates a corrected draft with the same lines to edit
 * and issue ("void and re-issue"). Paid invoices cannot be voided; drafts are deleted instead.
 */
class VoidInvoice
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly ReleaseBillingHolds $releaseHolds,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @return array{0: Invoice, 1: Invoice|null} the void invoice and the new draft (when asked for)
     *
     * @throws ValidationException
     */
    public function handle(Invoice $invoice, string $reason, bool $redraft = false): array
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Enter why the invoice is void.']);
        }

        /** @var Company $company */
        $company = $invoice->company()->firstOrFail();

        $result = DB::transaction(function () use ($invoice, $company, $reason, $redraft) {
            $now = CarbonImmutable::now();
            $this->accounts->lock($company);
            $invoice = Invoice::withoutCompanyScope()->lockForUpdate()->findOrFail($invoice->id);

            if (! $invoice->isOpen()) {
                throw ValidationException::withMessages(['status' => match ($invoice->status) {
                    InvoiceStatus::Draft => 'A draft has no number yet: delete it instead.',
                    InvoiceStatus::Paid => "{$invoice->number} is paid, so it cannot be voided.",
                    default => "{$invoice->number} is already void.",
                }]);
            }

            $released = $this->releaseAllocations($invoice, $now);
            $before = ['status' => $invoice->status->value, 'balance' => $invoice->balance];

            $invoice->forceFill([
                'status' => InvoiceStatus::Void,
                'amount_paid' => '0.00',
                'balance' => '0.00',
                'voided_at' => $now,
                'void_reason' => mb_substr($reason, 0, 500),
                'voided_by_admin_id' => Actor::adminId(),
            ])->save();

            $draft = $redraft ? $this->redraft($invoice) : null;

            $this->audit->handle('invoice.voided', $invoice, $before, ['status' => InvoiceStatus::Void->value], [
                'number' => $invoice->number,
                'reason' => $reason,
                'released_to_credit' => $released,
                'redraft_id' => $draft?->id,
            ]);

            return [$invoice, $draft];
        });

        $this->releaseHolds->handle($company);

        return $result;
    }

    /** Payments on the invoice go back to credit. Returns the amount released. */
    private function releaseAllocations(Invoice $invoice, CarbonImmutable $now): string
    {
        $released = [];

        foreach (PaymentAllocation::withoutCompanyScope()->where('invoice_id', $invoice->id)->whereNull('released_at')->lockForUpdate()->get() as $allocation) {
            $payment = Payment::withoutCompanyScope()->lockForUpdate()->findOrFail($allocation->payment_id);
            $payment->unallocated = Money::add($payment->unallocated, $allocation->amount);
            $payment->save();

            $allocation->released_at = $now;
            $allocation->save();

            $released[] = $allocation->amount;
        }

        return Money::sum($released);
    }

    private function redraft(Invoice $void): Invoice
    {
        $draft = new Invoice([
            'status' => InvoiceStatus::Draft,
            'cycle' => $void->cycle,
            'period_start' => $void->period_start,
            'period_end' => $void->period_end,
            'vat_rate' => $void->vat_rate,
            'subtotal' => $void->subtotal,
            'vat_total' => $void->vat_total,
            'total' => $void->total,
            'balance' => $void->total,
            'notes' => $void->notes,
            'prorated' => $void->prorated,
            'replaces_invoice_id' => $void->id,
            'created_by_admin_id' => Actor::adminId(),
        ]);
        $draft->company_id = $void->company_id;
        $draft->save();

        foreach ($void->lines as $line) {
            /** @var InvoiceLine $line */
            $copy = $line->replicate(['id', 'invoice_id', 'created_at', 'updated_at']);
            $copy->invoice_id = $draft->id;
            $copy->save();
        }

        $this->audit->handle('invoice.created', $draft, null, [
            'period_start' => $draft->period_start->format('Y-m-d'),
            'period_end' => $draft->period_end->format('Y-m-d'),
            'total' => $draft->total,
        ], ['replaces' => $void->number, 'lines' => $void->lines->count()]);

        return $draft;
    }
}
