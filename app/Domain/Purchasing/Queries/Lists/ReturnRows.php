<?php

namespace App\Domain\Purchasing\Queries\Lists;

use App\Domain\Purchasing\Support\PurchasingNames;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Enums\PurchaseReturnStatus;
use App\Domain\TillData\Models\PurchaseReturn;
use Illuminate\Database\Eloquent\Builder;

/**
 * Purchase returns (goods sent back to a supplier; PR-LDS-00012), read only: Draft → Sent → Credited or Cancelled,
 * all at the shop.
 *
 * @extends DocumentRows<PurchaseReturn>
 */
final class ReturnRows extends DocumentRows
{
    protected function base(): Builder
    {
        return PurchaseReturn::query();
    }

    public function statuses(): array
    {
        return array_map(fn (PurchaseReturnStatus $s) => $s->value, PurchaseReturnStatus::cases());
    }

    protected function sortable(): array
    {
        return ['return_date', 'gross_amount', 'reference'];
    }

    protected function defaultSort(): array
    {
        return ['return_date', 'desc'];
    }

    protected function search(Builder $query, string $like): void
    {
        $query->where('reference', 'like', $like)->orWhere('supplier_name', 'like', $like)
            ->orWhere('credit_note_number', 'like', $like)->orWhere('note', 'like', $like);
        $this->supplierMatch($query, $like);
    }

    protected function rows(array $rows, PurchasingNames $names): array
    {
        return array_map(fn (PurchaseReturn $r) => [
            'id' => $r->id,
            'reference' => $r->reference ?: sprintf('PR-%05d', $r->number),
            'status' => $r->status?->value,
            'shop' => $names->shop($r->branch_id),
            'supplier' => $names->supplier($r->supplier_id, $r->supplier_name),
            'date' => $r->return_date->format('Y-m-d'),
            'creditNote' => $r->credit_note_number ?: null,
            'gross' => $r->gross_amount,
        ], $rows);
    }

    public function stats(Builder $query): array
    {
        $sent = (clone $query)->where('status', 'sent');

        return [
            self::stat('Awaiting credit', (clone $sent)->count(), 'count', 'warning', 'Sent back, no credit yet'),
            self::stat('Value awaiting credit', Money::normalise((clone $sent)->sum('gross_amount') ?: 0), 'money', 'neutral', 'Inc. VAT'),
            self::stat('Credited', Money::normalise((clone $query)->where('status', 'credited')->sum('gross_amount') ?: 0), 'money', 'success', 'All time'),
            self::stat('Drafts', (clone $query)->where('status', 'draft')->count(), 'count', 'neutral', 'Not sent yet'),
        ];
    }
}
