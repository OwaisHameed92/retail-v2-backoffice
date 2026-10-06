<?php

namespace App\Domain\Purchasing\Queries\Lists;

use App\Domain\Purchasing\Support\PurchasingNames;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Enums\SupplierInvoiceStatus;
use App\Domain\TillData\Models\SupplierInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Supplier invoices keyed in at the shops, read only, with the delivery (GRN) each is for.
 *
 * @extends DocumentRows<SupplierInvoice>
 */
final class InvoiceRows extends DocumentRows
{
    protected function base(): Builder
    {
        return SupplierInvoice::query();
    }

    public function statuses(): array
    {
        return array_map(fn (SupplierInvoiceStatus $s) => $s->value, SupplierInvoiceStatus::cases());
    }

    protected function sortable(): array
    {
        return ['invoice_date', 'due_date', 'gross_amount', 'balance'];
    }

    protected function defaultSort(): array
    {
        return ['invoice_date', 'desc'];
    }

    protected function search(Builder $query, string $like): void
    {
        $query->where('invoice_number', 'like', $like)->orWhere('supplier_name', 'like', $like)->orWhere('notes', 'like', $like);
        $this->supplierMatch($query, $like);
    }

    protected function rows(array $rows, PurchasingNames $names): array
    {
        $today = CarbonImmutable::now(Country::zone())->format('Y-m-d');
        $deliveries = DB::table('goods_receipts')->whereIn('id', array_filter(array_map(fn ($r) => $r->goods_receipt_id, $rows)))
            ->pluck('delivery_note_number', 'id');

        return array_map(fn (SupplierInvoice $i) => [
            'id' => $i->id,
            'reference' => $i->invoice_number ?: 'No invoice number',
            'status' => $i->status?->value,
            'shop' => $names->shop($i->branch_id),
            'supplier' => $names->supplier($i->supplier_id, $i->supplier_name),
            'date' => $i->invoice_date->format('Y-m-d'),
            'dueDate' => $i->due_date->format('Y-m-d'),
            'overdue' => $i->due_date->format('Y-m-d') < $today && Money::compare($i->balance ?? '0', '0') > 0,
            'delivery' => $i->goods_receipt_id !== null ? ($deliveries[$i->goods_receipt_id] ?? 'Delivery') : null,
            'gross' => $i->gross_amount,
            'balance' => $i->balance,
        ], $rows);
    }

    public function stats(Builder $query): array
    {
        $today = CarbonImmutable::now(Country::zone())->format('Y-m-d');
        $unpaid = (clone $query)->where('status', '!=', 'draft')->where('balance', '>', 0);
        $overdue = (clone $unpaid)->where('due_date', '<', $today);

        return [
            self::stat('Owed to suppliers', Money::normalise((clone $unpaid)->sum('balance') ?: 0), 'money', 'primary', 'Unpaid invoice balances'),
            self::stat('Overdue', Money::normalise((clone $overdue)->sum('balance') ?: 0), 'money', 'danger', (clone $overdue)->count().' past their due date'),
            self::stat('Disputed', (clone $query)->where('status', 'disputed')->count(), 'count', 'warning', 'Queried with the supplier'),
            self::stat('Awaiting approval', (clone $query)->whereIn('status', ['draft', 'matched'])->count(), 'count', 'neutral', 'Draft or matched'),
        ];
    }
}
