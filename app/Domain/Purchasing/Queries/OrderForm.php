<?php

namespace App\Domain\Purchasing\Queries;

use App\Domain\Purchasing\Support\HeadOfficeOrders;
use App\Domain\Purchasing\Support\ReorderSuggestion;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\PurchaseOrder;
use App\Domain\TillData\Models\PurchaseOrderLine;
use App\Domain\TillData\Models\Supplier;
use App\Domain\TillData\Models\VatRate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Props for the head-office order form (module 5.2): the shop and supplier, the order's lines when editing, the
 * supplier's products with the shop's stock and a suggested number of cases, and a product search for anything
 * else. Costs are ex VAT (the supplier's case cost ÷ case size, else the product's cost price).
 */
final class OrderForm
{
    /** @return array<string, mixed> */
    public static function for(Request $request, ?PurchaseOrder $order): array
    {
        $shopId = $order->branch_id ?? self::text($request, 'shop');
        $supplierId = self::text($request, 'supplier') ?? $order?->supplier_id;
        $shop = $shopId !== null ? Branch::query()->where('is_active', true)->find($shopId) : null;
        $supplier = $supplierId !== null ? Supplier::query()->find($supplierId) : null;
        $search = self::text($request, 'q');

        return [
            'order' => $order === null ? null : self::order($order),
            'shopId' => $shop?->id,
            'supplierId' => $supplier?->id,
            'shops' => Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code'])
                ->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name, 'code' => $b->code])->values()->all(),
            'suppliers' => Supplier::query()->where('is_active', true)->orWhere('id', $supplier?->id)->orderBy('name')
                ->get(['id', 'name', 'default_lead_days', 'minimum_order_value'])
                ->map(fn (Supplier $s) => ['id' => $s->id, 'name' => (string) $s->name, 'leadDays' => $s->default_lead_days, 'minimumOrder' => $s->minimum_order_value])->values()->all(),
            'vatRates' => VatRate::query()->orderBy('percentage')->get(['id', 'name', 'percentage'])
                ->map(fn (VatRate $v) => ['id' => $v->id, 'name' => (string) $v->name, 'percentage' => Money::normalise($v->percentage ?? 0, 4)])->values()->all(),
            'suggestions' => $supplier === null ? [] : self::products($shop, $supplier, null),
            'search' => $search,
            'results' => $search === null ? [] : self::products($shop, $supplier, $search),
        ];
    }

    /** @return array<string, mixed> */
    private static function order(PurchaseOrder $order): array
    {
        $lines = PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->orderBy('position')->get();
        $products = Product::query()->withTrashed()->whereKey($lines->pluck('product_id'))->get(['id', 'name', 'sku'])->keyBy('id');
        $stock = self::stock($order->branch_id, $lines->pluck('product_id')->all());

        return [
            'id' => $order->id, 'reference' => HeadOfficeOrders::reference($order), 'status' => $order->status?->value,
            'supplierId' => $order->supplier_id, 'expectedDate' => $order->expected_date?->format('Y-m-d'), 'notes' => $order->notes ?? '',
            'lines' => $lines->map(fn (PurchaseOrderLine $l) => [
                'productId' => $l->product_id, 'name' => (string) ($products[$l->product_id]->name ?? 'Unknown product'), 'sku' => $products[$l->product_id]->sku ?? null,
                'orderedCases' => (int) $l->ordered_cases, 'caseQty' => max(1, (int) $l->case_qty_snapshot),
                'looseUnits' => max(0, (int) bcsub($l->ordered_units ?? '0', (string) ($l->ordered_cases * $l->case_qty_snapshot), 0)),
                'unitCost' => Money::normalise($l->unit_cost_snapshot ?? 0, 4), 'vatRateId' => $l->vat_rate_id,
                'onHand' => self::quantity($stock[$l->product_id]->qty_on_hand ?? null),
            ])->values()->all(),
        ];
    }

    /**
     * The supplier's products (with a suggestion), or a product search (any product, the supplier's case size and cost
     * when it has one).
     *
     * @return list<array<string, mixed>>
     */
    private static function products(?Branch $shop, ?Supplier $supplier, ?string $search): array
    {
        $company = app(CurrentCompany::class)->id();
        $query = Product::query()->whereNull('products.archived_at')->where(fn ($q) => $q->where('products.is_active', true)->orWhereNull('products.is_active'))
            ->leftJoin('product_suppliers as ps', fn ($j) => $j->on('ps.product_id', '=', 'products.id')->where('ps.company_id', $company)
                ->where('ps.supplier_id', $supplier->id ?? '')->whereNull('ps.deleted_at'))
            ->select(['products.id', 'products.name', 'products.sku', 'products.cost_price', 'products.vat_rate_id', 'products.min_stock_qty',
                'products.max_stock_qty', 'products.reorder_qty', 'ps.case_qty', 'ps.case_cost', 'ps.supplier_sku', 'ps.is_preferred']);

        $search === null
            ? $query->whereNotNull('ps.id')->orderByDesc('ps.is_preferred')->orderBy('products.name')->limit(200)
            : $query->where(fn ($q) => $q->where('products.name', 'like', "%{$search}%")->orWhere('products.sku', 'like', "%{$search}%")
                ->orWhereIn('products.id', DB::table('product_barcodes')->where('company_id', $company)->where('barcode', $search)->select('product_id')))
                ->orderBy('products.name')->limit(20);

        $rows = $query->get();
        $stock = self::stock($shop?->id, $rows->pluck('id')->all());

        return $rows->map(function (Product $p) use ($stock) {
            $caseQty = max(1, (int) ($p->getAttribute('case_qty') ?? 1));
            $caseCost = $p->getAttribute('case_cost');
            $unitCost = $caseCost !== null && Money::compare($caseCost, '0') > 0 ? bcdiv((string) $caseCost, (string) $caseQty, 4) : Money::normalise($p->cost_price ?? 0, 4);
            $level = $stock[$p->id] ?? null;
            $onHand = self::quantity($level?->qty_on_hand);

            return [
                'productId' => $p->id, 'name' => (string) $p->name, 'sku' => $p->sku ?: null, 'supplierSku' => $p->getAttribute('supplier_sku') ?: null,
                'caseQty' => $caseQty, 'unitCost' => $unitCost, 'vatRateId' => $p->vat_rate_id, 'onHand' => $onHand,
                'suggestedCases' => ReorderSuggestion::cases($onHand, self::quantity($level->reorder_point ?? $p->min_stock_qty),
                    self::quantity($level->max_qty ?? $p->max_stock_qty), self::quantity($p->reorder_qty), $caseQty),
            ];
        })->values()->all();
    }

    /**
     * @param  list<string>  $productIds
     * @return array<string, object{qty_on_hand: string|null, reorder_point: string|null, max_qty: string|null}>
     */
    private static function stock(?string $shopId, array $productIds): array
    {
        if ($shopId === null || $productIds === []) {
            return [];
        }

        return DB::table('branch_products')->where('company_id', app(CurrentCompany::class)->id())->where('branch_id', $shopId)
            ->whereIn('product_id', $productIds)->whereNull('deleted_at')->get(['product_id', 'qty_on_hand', 'reorder_point', 'max_qty'])
            ->keyBy('product_id')->all();
    }

    /** A stock figure as a 4 dp string (SQLite hands decimals back as numbers), or null when not known. */
    private static function quantity(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : Money::normalise($value, 4);
    }

    private static function text(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
