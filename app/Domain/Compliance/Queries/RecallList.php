<?php

namespace App\Domain\Compliance\Queries;

use App\Domain\Compliance\Data\ComplianceFilters;
use App\Domain\Compliance\Support\ComplianceLookup as L;
use App\Domain\Compliance\Support\RecallShops;
use App\Domain\Shared\Support\Money;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\TillData\Enums\StockMovementType;
use App\Domain\TillData\Models\BranchProduct;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductRecall;
use App\Domain\TillData\Models\StockLayer;
use App\Domain\TillData\Models\StockMovement;
use App\Domain\TillData\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Product recalls (module 5.7): hub-owned rows the portal raises and every till applies. The list (open first,
 * newest raised first) and one recall with its state in each shop and the stock it touches there. State: each shop's
 * own close / reopen (`ProductRecallBranchState`, till 0.1.52; no row = open there, RecallShops), never the company
 * row's `status`. Stock: on hand of the product, and what is left of deliveries whose batch number or best-before date
 * matches (`StockLayer`), when the tills track them. Returned: each shop's till `supplierReturn` stock movements for the
 * recall (`refType` "Recall", `refId` the recall's id), never the row's `returnedQty` (ANSWERS-2026-10-06 Q3).
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
        $shops = RecallShops::ids($f->shop);
        $query = RecallShops::openFirst(ProductRecall::query(), $shops)
            ->when($f->status === 'open' || $f->status === 'closed', fn (Builder $q) => RecallShops::where($q, $shops, $f->status === 'open'));
        $page = $table->paginator($query);
        /** @var list<ProductRecall> $rows */
        $rows = $page->items();
        $onHand = self::onHand($f, array_map(fn (ProductRecall $r) => $r->product_id, $rows));
        $closed = RecallShops::closed(array_map(fn (ProductRecall $r) => (string) $r->id, $rows), $shops);

        return [
            'recalls' => [
                'data' => array_map(fn (ProductRecall $r) => [
                    ...self::row($r),
                    ...RecallShops::status($closed[$r->id] ?? 0, count($shops)),
                    'onHand' => $r->product_id !== '' ? ($onHand[$r->product_id] ?? '0.0000') : null,
                ], $rows),
                'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage(), 'search' => $table->search(), 'sort' => $table->sort(), 'direction' => $table->direction()],
            ],
            'summary' => [
                'open' => RecallShops::where(ProductRecall::query(), $shops, true)->count(),
                'closed' => RecallShops::where(ProductRecall::query(), $shops, false)->count(),
                'shops' => count($shops),
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
        $returned = self::returned($r, $f);
        $ids = $onHand->keys()->merge($batches->keys())->unique()->values()->all();
        $shops = L::shops([...$ids, ...$returned->keys()->all()]);
        $supplier = $r->supplier_id !== '' ? Supplier::query()->withTrashed()->find($r->supplier_id, ['id', 'name']) : null;
        $counted = RecallShops::ids($f->shop);

        return [
            'recall' => [
                ...self::row($r),
                ...RecallShops::status(RecallShops::closed([(string) $r->id], $counted)[$r->id] ?? 0, count($counted)),
                'productId' => L::blank($r->product_id),
                'supplierId' => L::blank($r->supplier_id),
                'supplier' => $supplier?->name,
                'returnedQty' => Money::sum($returned->values(), 4),
                'fromPortal' => $r->origin_branch_id === null,
            ],
            'shops' => RecallShops::states($r, $counted, $returned),
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
     * Quantity each shop returned to the supplier against this recall (a return's `qtyDelta` takes stock out).
     *
     * @return Collection<string, string> branch id → quantity
     */
    private static function returned(ProductRecall $r, ComplianceFilters $f): Collection
    {
        return $f->scope(StockMovement::query())->where('ref_type', 'Recall')->where('ref_id', $r->id)
            ->where('type', StockMovementType::SupplierReturn->value)->get(['branch_id', 'qty_delta'])
            ->groupBy(fn (StockMovement $m) => (string) $m->branch_id)
            ->map(fn ($rows) => ltrim(Money::sum($rows->pluck('qty_delta'), 4), '-'));
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
