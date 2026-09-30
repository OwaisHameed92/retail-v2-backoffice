<?php

namespace App\Domain\Stock\Queries;

use App\Domain\Shared\Support\Money;
use App\Domain\Stock\Data\StockFilters;
use App\Domain\Stock\Support\FifoValuation;
use App\Domain\TillData\Models\FifoStockLayer;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\StockLayer;
use App\Domain\TillData\Models\StockMovement;
use Illuminate\Database\Eloquent\Builder;

/**
 * One product's stock (module 5.1): each shop's line (on hand, reserved, low-stock point, min/max, FIFO value), the
 * product's own stock levels (hub-owned, editable with `stock.manage`), its FIFO cost layers, its dated batches and
 * its latest movements. A one-shop user sees only their shop (`$f->shop` is pinned).
 */
final class ProductStock
{
    public const RECENT = 10;

    public function __construct(private readonly StockLines $lines, private readonly StockValuation $valuation) {}

    /** @return array<string, mixed> */
    public function for(Product $product, StockFilters $f): array
    {
        $companyId = (string) $product->company_id;
        $scoped = new StockFilters(shop: $f->shop, shopLocked: $f->shopLocked, product: $product->id);
        [$t, $bindings] = $this->lines->threshold($companyId);
        $layers = $this->valuation->layers($companyId, $scoped);
        $shops = StockNames::shops();

        $lines = $this->lines->base($companyId, $scoped)
            ->select(['bp.id', 'bp.branch_id', 'bp.qty_on_hand', 'bp.qty_reserved', 'bp.qty_available', 'bp.reorder_point', 'bp.min_qty', 'bp.max_qty', 'p.cost_price'])
            ->selectRaw("{$t} as threshold", $bindings)->get()
            ->map(function (object $r) use ($layers, $shops, $product) {
                $onHand = Money::normalise($r->qty_on_hand ?? 0, 4);
                $fifo = FifoValuation::of($onHand, $layers[$r->branch_id.'|'.$product->id] ?? [], $r->cost_price);
                $q = fn ($v) => $v !== null ? Money::normalise($v, 4) : null;

                return [
                    'id' => (string) $r->id,
                    'shopId' => (string) $r->branch_id,
                    'shop' => $shops[$r->branch_id] ?? 'Unknown shop',
                    'onHand' => $onHand,
                    'reserved' => Money::normalise($r->qty_reserved ?? 0, 4),
                    'available' => $r->qty_available !== null ? Money::normalise($r->qty_available, 4) : Money::sub($onHand, $r->qty_reserved ?? 0, 4),
                    'lowAt' => Money::normalise($r->threshold ?? 0, 4),
                    'reorderPoint' => $q($r->reorder_point),
                    'min' => $q($r->min_qty),
                    'max' => $q($r->max_qty),
                    'status' => StockOnHand::status($onHand, Money::normalise($r->threshold ?? 0, 4)),
                    'fifoValue' => $fifo['value'],
                    'costValue' => Money::round(Money::mul(Money::compare($onHand, '0') > 0 ? $onHand : '0', $r->cost_price ?? 0, 4), 2),
                    'basis' => $fifo['basis'],
                ];
            })->sortBy('shop')->values()->all();

        return [
            'product' => [
                'id' => $product->id,
                'name' => $product->name !== '' ? $product->name : 'Unknown product',
                'sku' => (string) ($product->sku ?? ''),
                'costPrice' => Money::normalise($product->cost_price ?? 0, 4),
                'trackStock' => (bool) $product->track_stock,
                'tracksExpiryDates' => (bool) $product->tracks_expiry_dates,
                'negativeStockMode' => $product->negative_stock_mode?->value,
                'minStockQty' => $product->min_stock_qty !== null ? Money::normalise($product->min_stock_qty, 4) : null,
                'maxStockQty' => $product->max_stock_qty !== null ? Money::normalise($product->max_stock_qty, 4) : null,
                'reorderQty' => $product->reorder_qty !== null ? Money::normalise($product->reorder_qty, 4) : null,
                'isActive' => (bool) $product->is_active,
            ],
            'lines' => $lines,
            'totals' => [
                'onHand' => Money::sum(array_column($lines, 'onHand'), 4),
                'fifoValue' => Money::sum(array_column($lines, 'fifoValue')),
                'costValue' => Money::sum(array_column($lines, 'costValue')),
            ],
            'layers' => FifoStockLayer::query()->where('product_id', $product->id)->where('qty_remaining', '>', 0)
                ->when($f->shop !== null, fn (Builder $q) => $q->where('branch_id', $f->shop))
                ->orderByDesc('received_at')->orderByDesc('id')->limit(100)->get()
                ->map(fn (FifoStockLayer $l) => [
                    'id' => $l->id,
                    'shop' => $shops[$l->branch_id] ?? 'Unknown shop',
                    'receivedAt' => $l->received_at->toIso8601ZuluString(),
                    'qty' => Money::normalise($l->qty_remaining ?? 0, 4),
                    'unitCost' => Money::normalise($l->unit_cost ?? 0, 4),
                    'value' => Money::round(Money::mul($l->qty_remaining ?? 0, $l->unit_cost ?? 0, 4), 2),
                    'source' => (string) ($l->ref_type ?? '') !== '' ? $l->ref_type : null,
                ])->values()->all(),
            'batches' => StockLayer::query()->where('product_id', $product->id)->where('qty_remaining', '>', 0)
                ->when($f->shop !== null, fn (Builder $q) => $q->where('branch_id', $f->shop))
                ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')->orderBy('expiry_date')->orderBy('id')->limit(100)->get()
                ->map(fn (StockLayer $l) => [
                    'id' => $l->id,
                    'shop' => $shops[$l->branch_id] ?? 'Unknown shop',
                    'batch' => $l->batch_no,
                    'expiry' => $l->expiry_date?->format('Y-m-d'),
                    'receivedAt' => $l->received_at->toIso8601ZuluString(),
                    'qty' => Money::normalise($l->qty_remaining ?? 0, 4),
                    'unitCost' => Money::normalise($l->unit_cost ?? 0, 4),
                ])->values()->all(),
            'recent' => MovementList::rows(StockMovement::query()->where('product_id', $product->id)
                ->when($f->shop !== null, fn (Builder $q) => $q->where('branch_id', $f->shop))
                ->orderByDesc('at')->orderByDesc('id')->limit(self::RECENT)->get()),
        ];
    }
}
