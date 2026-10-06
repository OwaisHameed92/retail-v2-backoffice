<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\InvoiceMaths;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Country\MoneyFormat;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Edits a draft before it is issued: notes and lines (description, quantity, unit price; remove lines; add
 * custom lines without a licence). Lines kept from the generated draft keep their licence, so paying the
 * invoice still renews it. Issued invoices never change.
 */
class UpdateDraftInvoice
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @param  list<array{id?: string|null, description: string, quantity: string, unit_price: string}>  $lines
     *
     * @throws ValidationException
     */
    public function handle(Invoice $invoice, ?string $notes, array $lines): Invoice
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'An invoice needs at least one line.']);
        }

        /** @var Company $company */
        $company = $invoice->company()->firstOrFail();

        return DB::transaction(function () use ($invoice, $company, $notes, $lines) {
            $this->accounts->lock($company);
            $invoice = Invoice::withoutCompanyScope()->lockForUpdate()->findOrFail($invoice->id);

            if (! $invoice->isDraft()) {
                throw ValidationException::withMessages(['status' => "{$invoice->number} is issued, so it cannot be changed. Void it and re-issue, or add a credit note."]);
            }

            $existing = $invoice->lines->keyBy('id');
            $before = ['total' => $invoice->total, 'lines' => $existing->count()];
            $keep = [];
            $amounts = [];

            foreach ($lines as $index => $input) {
                $quantity = Money::normalise($input['quantity'], Money::QUANTITY_SCALE);
                $unitPrice = Money::normalise($input['unit_price']);
                $values = InvoiceMaths::line($quantity, $unitPrice, $invoice->vat_rate);
                $amounts[] = $values;

                $id = $input['id'] ?? null;
                /** @var InvoiceLine $line */
                $line = $id !== null && $existing->has($id) ? $existing->get($id) : new InvoiceLine;

                $line->forceFill([
                    'position' => $index + 1,
                    'description' => mb_substr(trim($input['description']), 0, 500),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'net' => $values['net'],
                    'vat' => $values['vat'],
                    'gross' => $values['gross'],
                ]);

                if (! $line->exists) {
                    $line->company_id = $invoice->company_id;
                    $line->invoice_id = $invoice->id;
                    $line->period_start = $invoice->period_start;
                    $line->period_end = $invoice->period_end;
                }

                $line->save();
                $keep[] = $line->id;
            }

            InvoiceLine::withoutCompanyScope()->where('invoice_id', $invoice->id)->whereNotIn('id', $keep)->delete();

            $totals = InvoiceMaths::totals($amounts);

            if (Money::isNegative($totals['total'])) {
                throw ValidationException::withMessages(['lines' => 'Discount lines cannot take the invoice total below '.MoneyFormat::format('0').'.']);
            }

            $notes = trim((string) $notes);

            $invoice->forceFill([
                'subtotal' => $totals['subtotal'],
                'vat_total' => $totals['vat_total'],
                'total' => $totals['total'],
                'balance' => $totals['total'],
                'notes' => $notes === '' ? null : mb_substr($notes, 0, 2000),
            ])->save();

            $this->audit->handle('invoice.updated', $invoice, $before, ['total' => $totals['total'], 'lines' => count($keep)]);

            return $invoice->refresh();
        });
    }
}
