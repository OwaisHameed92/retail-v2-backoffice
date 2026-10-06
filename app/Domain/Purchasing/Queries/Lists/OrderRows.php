<?php

namespace App\Domain\Purchasing\Queries\Lists;

use App\Domain\Purchasing\Data\PurchasingFilters;
use App\Domain\Purchasing\Support\HeadOfficeOrders;
use App\Domain\Purchasing\Support\PurchasingNames;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Enums\PurchaseOrderStatus;
use App\Domain\TillData\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Builder;

/**
 * Purchase orders: the shops' own (read only) and head-office orders drafted here. Shown by `reference`
 * (PO-LDS-000001): order numbers repeat across shops (contract §10.6).
 *
 * @extends DocumentRows<PurchaseOrder>
 */
final class OrderRows extends DocumentRows
{
    private const OPEN = ['sent', 'partReceived'];

    protected function base(): Builder
    {
        return PurchaseOrder::query();
    }

    public function statuses(): array
    {
        return array_map(fn (PurchaseOrderStatus $s) => $s->value, PurchaseOrderStatus::cases());
    }

    protected function sortable(): array
    {
        return ['created_at', 'expected_date', 'gross_total', 'reference'];
    }

    protected function defaultSort(): array
    {
        return ['created_at', 'desc'];
    }

    public function filtered(PurchasingFilters $filters): Builder
    {
        $query = parent::filtered($filters);

        if ($filters->origin !== null) {
            $query->where('origin', $filters->origin);
        }

        return $query;
    }

    protected function search(Builder $query, string $like): void
    {
        $query->where('reference', 'like', $like)->orWhere('order_no', 'like', $like)->orWhere('notes', 'like', $like);
        $this->supplierMatch($query, $like);
    }

    protected function rows(array $rows, PurchasingNames $names): array
    {
        $lines = PurchaseOrder::query()->getConnection()->table('purchase_order_lines')->whereIn('purchase_order_id', array_map(fn ($o) => $o->id, $rows))
            ->whereNull('deleted_at')->groupBy('purchase_order_id')->selectRaw('purchase_order_id, count(*) as n')->pluck('n', 'purchase_order_id');

        return array_map(fn (PurchaseOrder $o) => [
            'id' => $o->id,
            'reference' => HeadOfficeOrders::reference($o),
            'origin' => $o->origin->value ?? 'branch',
            'status' => $o->status?->value,
            'shop' => $names->shop($o->branch_id),
            'supplier' => $names->supplier($o->supplier_id),
            'supplierId' => $o->supplier_id,
            'expectedDate' => $o->expected_date?->format('Y-m-d'),
            'sentAt' => $o->sent_at?->toIso8601ZuluString(),
            'createdAt' => $o->created_at?->toIso8601ZuluString(),
            'lines' => (int) ($lines[$o->id] ?? 0),
            'gross' => $o->gross_total,
            'withPortal' => HeadOfficeOrders::portalOwns($o),
        ], $rows);
    }

    public function stats(Builder $query): array
    {
        $open = (clone $query)->whereIn('status', self::OPEN);
        $drafts = (clone $query)->whereNotNull('hub_drafted_at')->whereNull('origin_branch_id')->where('status', '!=', 'cancelled');

        return [
            self::stat('Open orders', (clone $open)->count(), 'count', 'primary', 'Sent or part received'),
            self::stat('Value on order', Money::normalise((clone $open)->sum('gross_total') ?: 0), 'money', 'neutral', Country::tax('Open orders, inc. VAT')),
            self::stat('With the portal', $drafts->count(), 'count', 'neutral', 'Head-office orders the shop has not taken on yet'),
            self::stat('Awaiting invoice', (clone $query)->where('status', 'received')->count(), 'count', 'warning', 'Received in full'),
        ];
    }
}
