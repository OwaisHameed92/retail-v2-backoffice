<?php

namespace App\Domain\Purchasing\Queries\Lists;

use App\Domain\Purchasing\Support\PurchasingNames;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payments made to suppliers (recorded at a shop), read only. A reversed payment is kept and shown struck out.
 *
 * @extends DocumentRows<SupplierPayment>
 */
final class PaymentRows extends DocumentRows
{
    protected function base(): Builder
    {
        return SupplierPayment::query();
    }

    public function statuses(): array
    {
        return ['current', 'reversed'];
    }

    protected function status(Builder $query, string $status): void
    {
        $status === 'reversed' ? $query->where('is_reversed', true) : $query->where(fn ($q) => $q->where('is_reversed', false)->orWhereNull('is_reversed'));
    }

    protected function sortable(): array
    {
        return ['payment_date', 'amount'];
    }

    protected function defaultSort(): array
    {
        return ['payment_date', 'desc'];
    }

    protected function search(Builder $query, string $like): void
    {
        $query->where('reference', 'like', $like)->orWhere('supplier_name', 'like', $like)->orWhere('notes', 'like', $like);
        $this->supplierMatch($query, $like);
    }

    protected function rows(array $rows, PurchasingNames $names): array
    {
        return array_map(fn (SupplierPayment $p) => [
            'id' => $p->id,
            'reference' => $p->reference ?: 'No reference',
            'status' => $p->is_reversed ? 'reversed' : 'current',
            'shop' => $names->shop($p->branch_id),
            'supplier' => $names->supplier($p->supplier_id, $p->supplier_name),
            'date' => $p->payment_date->format('Y-m-d'),
            'method' => $p->method?->value,
            'gross' => $p->amount,
            'unallocated' => $p->unallocated,
        ], $rows);
    }

    public function stats(Builder $query): array
    {
        $current = (clone $query)->where(fn ($q) => $q->where('is_reversed', false)->orWhereNull('is_reversed'));

        return [
            self::stat('Payments', (clone $current)->count(), 'count', 'primary', 'Not reversed'),
            self::stat('Paid', Money::normalise((clone $current)->sum('amount') ?: 0), 'money', 'success', 'All time'),
            self::stat('Not allocated', Money::normalise((clone $current)->where('unallocated', '>', 0)->sum('unallocated') ?: 0), 'money', 'neutral', 'Paid on account'),
        ];
    }
}
