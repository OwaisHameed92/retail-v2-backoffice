<?php

namespace App\Domain\Purchasing\Queries\Lists;

use App\Domain\Purchasing\Support\PurchasingNames;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Enums\GoodsReceiptStatus;
use App\Domain\TillData\Models\GoodsReceipt;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Goods receipts (deliveries booked in at a shop, GRNs), read only, with the damaged quantity of their lines.
 *
 * @extends DocumentRows<GoodsReceipt>
 */
final class DeliveryRows extends DocumentRows
{
    protected function base(): Builder
    {
        return GoodsReceipt::query();
    }

    public function statuses(): array
    {
        return array_map(fn (GoodsReceiptStatus $s) => $s->value, GoodsReceiptStatus::cases());
    }

    protected function sortable(): array
    {
        return ['received_date', 'gross_amount', 'delivery_note_number'];
    }

    protected function defaultSort(): array
    {
        return ['received_date', 'desc'];
    }

    protected function search(Builder $query, string $like): void
    {
        $query->where('delivery_note_number', 'like', $like)->orWhere('supplier_name', 'like', $like)->orWhere('note', 'like', $like);
        $this->supplierMatch($query, $like);
    }

    protected function rows(array $rows, PurchasingNames $names): array
    {
        $ids = array_map(fn ($r) => $r->id, $rows);
        $damaged = DB::table('goods_receipt_lines')->whereIn('goods_receipt_id', $ids)->whereNull('deleted_at')
            ->groupBy('goods_receipt_id')->selectRaw('goods_receipt_id, count(*) as n, sum(coalesce(damaged_qty, 0)) as damaged')->get()->keyBy('goods_receipt_id');
        $orders = DB::table('purchase_orders')->whereIn('id', array_filter(array_map(fn ($r) => $r->purchase_order_id, $rows)))
            ->get(['id', 'reference', 'order_no'])->keyBy('id');

        return array_map(function (GoodsReceipt $r) use ($names, $damaged, $orders) {
            $order = $r->purchase_order_id !== null ? $orders->get($r->purchase_order_id) : null;

            return [
                'id' => $r->id,
                'reference' => $r->delivery_note_number ?: 'No delivery note',
                'status' => $r->status?->value,
                'shop' => $names->shop($r->branch_id),
                'supplier' => $names->supplier($r->supplier_id, $r->supplier_name),
                'date' => $r->received_date->format('Y-m-d'),
                'order' => $order === null ? null : ($order->reference ?: $order->order_no),
                'lines' => (int) ($damaged->get($r->id)->n ?? 0),
                'damaged' => Money::normalise($damaged->get($r->id)->damaged ?? 0, 4),
                'gross' => $r->gross_amount,
            ];
        }, $rows);
    }

    public function stats(Builder $query): array
    {
        $since = CarbonImmutable::now('Europe/London')->subDays(30)->format('Y-m-d');
        $posted = (clone $query)->where('status', 'posted')->where('received_date', '>=', $since);
        $damaged = DB::table('goods_receipt_lines')->whereIn('goods_receipt_id', (clone $query)->where('received_date', '>=', $since)->select('id'))
            ->whereNull('deleted_at')->where('damaged_qty', '>', 0);

        return [
            self::stat('Deliveries', (clone $posted)->count(), 'count', 'primary', 'Posted, last 30 days'),
            self::stat('Goods in value', Money::normalise((clone $posted)->sum('gross_amount') ?: 0), 'money', 'neutral', 'Last 30 days, inc. VAT'),
            self::stat('Lines with damage', $damaged->count(), 'count', 'warning', 'Last 30 days'),
            self::stat('Still open', (clone $query)->where('status', 'draft')->count(), 'count', 'neutral', 'Being booked in'),
        ];
    }
}
