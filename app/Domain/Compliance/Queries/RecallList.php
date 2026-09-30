<?php

namespace App\Domain\Compliance\Queries;

use App\Domain\Compliance\Data\ComplianceFilters;
use App\Domain\Compliance\Support\ComplianceLookup as L;
use App\Domain\Shared\Support\Money;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\TillData\Enums\ProductRecallStatus;
use App\Domain\TillData\Models\BranchProduct;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductRecall;
use App\Domain\TillData\Models\StockLayer;
use App\Domain\TillData\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Product recalls (module 5.7): hub-owned rows the portal raises and every till applies. The list (open first,
 * newest raised first) and one recall with the stock it touches in each shop: on hand of the product, and what is
 * left of deliveries whose batch number or best-before date matches (`StockLayer`), when the tills track them.
 */
final class RecallList
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request, ComplianceFilters $f): array
    {
        $table = TableQuery::from($request)->searchable(['reference', 'product_name', 'batch_code', 'reason'])
            ->sortable(['raised_at', 'reference'])->defaultSort('raised_at', 'desc')->defaultPerPage(25);
        $query = ProductRecall::query()
            ->when($f->status === 'open' || $f->status === 'closed', fn (Builder $q) => $q->where('status', $f->status))
            ->orderByRaw("case when status = 'open' then 0 else 1 end");
        $page = $table->paginator($query);
        /** @var list<ProductRecall> $rows */
        $rows = $page->items();
        $onHand = self::onHand($f, array_map(fn (ProductRecall $r) => $r->product_id, $rows));

        return [
            'recalls' => [
                'data' => array_map(fn (ProductRecall $r) => [...self::row($r), 'onHand' => $r->product_id !== '' ? ($onHand[$r->product_id] ?? '0.0000') : null], $rows),
                'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage(), 'search' => $table->search(), 'sort' => $table->sort(), 'direction' => $table->direction()],
            ],
            'summary' => [
                'open' => ProductRecall::query()->where('status', ProductRecallStatus::Open->value)->count(),
                'closed' => ProductRecall::query()->where('status', ProductRecallStatus::Closed->value)->count(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function show(ProductRecall $r, ComplianceFilters $f): array
    {
        $onHand = $r->product_id === '' ? collect() : $f->scope(BranchProduct::query())->where('product_id', $r->product_id)->get(['branch_id', 'qty_on_hand'])
            ->groupBy('branch_id')->map(fn ($rows) => Money::sum($rows->pluck('qty_on_hand'), 4));
        $batches = self::batches($r, $f)->groupBy('branch_id')->map(fn ($rows) => ['qty' => Money::sum($rows->pluck('qty_remaining'), 4), 'count' => $rows->count()]);
        $ids = $onHand->keys()->merge($batches->keys())->unique()->values()->all();
        $shops = L::shops($ids);
        $supplier = $r->supplier_id !== '' ? Supplier::query()->withTrashed()->find($r->supplier_id, ['id', 'name']) : null;

        return [
            'recall' => [
                ...self::row($r),
                'productId' => L::blank($r->product_id),
                'supplierId' => L::blank($r->supplier_id),
                'supplier' => $supplier?->name,
                'returnedQty' => Money::normalise($r->returned_qty, 4),
                'closedAt' => L::iso($r->closed_at),
                'note' => L::blank($r->note),
                'fromPortal' => $r->origin_branch_id === null,
            ],
            'stock' => collect($ids)->map(fn (string $id) => [
                'shop' => L::name($shops, $id) ?? 'Unknown shop',
                'onHand' => $onHand[$id] ?? null,
                'batchQty' => $batches[$id]['qty'] ?? null,
                'batches' => $batches[$id]['count'] ?? 0,
            ])->sortBy('shop')->values()->all(),
            'matchesBatches' => $r->batch_code !== '' || $r->expiry_from !== null || $r->expiry_to !== null,
        ];
    }

    /**
     * Products for the recall form's picker (name or SKU match), at most 20.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function products(?string $q): array
    {
        $q = trim((string) $q);

        if (mb_strlen($q) < 2) {
            return [];
        }

        return Product::query()->where(fn (Builder $w) => $w->where('name', 'like', "%{$q}%")->orWhere('sku', 'like', "{$q}%"))
            ->orderBy('name')->limit(20)->get(['id', 'name', 'sku'])
            ->map(fn (Product $p) => ['value' => (string) $p->id, 'label' => (string) $p->name.((string) $p->sku !== '' ? ' · '.$p->sku : '')])->values()->all();
    }

    /** @return list<array{value: string, label: string}> */
    public static function suppliers(): array
    {
        return Supplier::query()->orderBy('name')->limit(500)->get(['id', 'name'])
            ->map(fn (Supplier $s) => ['value' => (string) $s->id, 'label' => (string) $s->name])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(ProductRecall $r): array
    {
        return [
            'id' => $r->id,
            'reference' => L::blank($r->reference),
            'product' => L::blank($r->product_name),
            'batchCode' => L::blank($r->batch_code),
            'expiryFrom' => $r->expiry_from?->format('Y-m-d'),
            'expiryTo' => $r->expiry_to?->format('Y-m-d'),
            'source' => L::blank($r->source),
            'reason' => L::blank($r->reason),
            'status' => $r->status?->value,
            'raisedAt' => L::iso($r->raised_at),
        ];
    }

    /**
     * On hand per product across the shops picked.
     *
     * @param  list<string>  $productIds
     * @return array<string, string>
     */
    private static function onHand(ComplianceFilters $f, array $productIds): array
    {
        $ids = array_values(array_unique(array_filter($productIds)));

        return $ids === [] ? [] : $f->scope(BranchProduct::query())->whereIn('product_id', $ids)->get(['product_id', 'qty_on_hand'])
            ->groupBy('product_id')->map(fn ($rows) => Money::sum($rows->pluck('qty_on_hand'), 4))->all();
    }

    /**
     * What is left of the recalled product's deliveries whose batch or best-before date matches.
     *
     * @return Collection<int, StockLayer>
     */
    private static function batches(ProductRecall $r, ComplianceFilters $f): Collection
    {
        if ($r->product_id === '' || ($r->batch_code === '' && $r->expiry_from === null && $r->expiry_to === null)) {
            return collect();
        }

        return $f->scope(StockLayer::query())->where('product_id', $r->product_id)->where('qty_remaining', '>', 0)
            ->when($r->batch_code !== '', fn (Builder $q) => $q->where('batch_no', $r->batch_code))
            ->when($r->expiry_from !== null, fn (Builder $q) => $q->where('expiry_date', '>=', $r->expiry_from?->format('Y-m-d')))
            ->when($r->expiry_to !== null, fn (Builder $q) => $q->where('expiry_date', '<=', $r->expiry_to?->format('Y-m-d')))
            ->get(['branch_id', 'qty_remaining']);
    }
}
