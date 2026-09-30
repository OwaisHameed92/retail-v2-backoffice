<?php

namespace App\Domain\Purchasing\Queries\Lists;

use App\Domain\Purchasing\Support\PurchasingNames;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Models\SupplierCreditNote;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Supplier credit notes (returns credited, rebates settled, price corrections), read only. "Unused" is the
 * balance not yet set against an invoice.
 *
 * @extends DocumentRows<SupplierCreditNote>
 */
final class CreditNoteRows extends DocumentRows
{
    protected function base(): Builder
    {
        return SupplierCreditNote::query();
    }

    public function statuses(): array
    {
        return ['unused', 'used'];
    }

    protected function status(Builder $query, string $status): void
    {
        $status === 'unused' ? $query->where('balance', '>', 0) : $query->where(fn ($q) => $q->where('balance', '<=', 0)->orWhereNull('balance'));
    }

    protected function sortable(): array
    {
        return ['credit_date', 'gross_amount', 'balance'];
    }

    protected function defaultSort(): array
    {
        return ['credit_date', 'desc'];
    }

    protected function search(Builder $query, string $like): void
    {
        $query->where('credit_note_number', 'like', $like)->orWhere('supplier_name', 'like', $like)->orWhere('reason', 'like', $like);
        $this->supplierMatch($query, $like);
    }

    protected function rows(array $rows, PurchasingNames $names): array
    {
        $invoices = DB::table('supplier_invoices')->whereIn('id', array_filter(array_map(fn ($r) => $r->linked_invoice_id, $rows)))
            ->pluck('invoice_number', 'id');

        return array_map(fn (SupplierCreditNote $c) => [
            'id' => $c->id,
            'reference' => $c->credit_note_number ?: 'No credit note number',
            'status' => Money::compare($c->balance ?? '0', '0') > 0 ? 'unused' : 'used',
            'shop' => $names->shop($c->branch_id),
            'supplier' => $names->supplier($c->supplier_id, $c->supplier_name),
            'date' => $c->credit_date->format('Y-m-d'),
            'reason' => $c->reason ?: null,
            'invoice' => $c->linked_invoice_id !== null ? ($invoices[$c->linked_invoice_id] ?? null) : null,
            'gross' => $c->gross_amount,
            'balance' => $c->balance,
        ], $rows);
    }

    public function stats(Builder $query): array
    {
        return [
            self::stat('Credit notes', (clone $query)->count(), 'count', 'primary'),
            self::stat('Credited', Money::normalise((clone $query)->sum('gross_amount') ?: 0), 'money', 'success', 'Inc. VAT'),
            self::stat('Not yet used', Money::normalise((clone $query)->where('balance', '>', 0)->sum('balance') ?: 0), 'money', 'neutral', 'Not set against an invoice'),
        ];
    }
}
