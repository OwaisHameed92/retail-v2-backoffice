<?php

namespace App\Domain\Ai\Tools\Portal;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Support\Portal\ShopPin;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductBarcode;
use App\Domain\TillData\Models\ProductSupplier;
use App\Domain\TillData\Models\Supplier;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read: find products of the business by name, SKU or barcode, with their prices and suppliers (case size and case
 * cost). Gives the ids the model needs before proposing an order.
 */
final class FindProducts extends PortalReadTool
{
    public function name(): string
    {
        return 'find_products';
    }

    public function description(): string
    {
        return 'Find products by part of the name, SKU or exact barcode (up to 10 matches): id, name, sell price, cost '
            .'price and each supplier with its id, case size and case cost. Use it to get product and supplier ids '
            .'before proposing a purchase order, or to answer price questions.';
    }

    public function inputSchema(): array
    {
        return self::object(['search' => ['type' => 'string', 'description' => 'Name, SKU or barcode, e.g. "coke 330".']], ['search']);
    }

    public function rules(): array
    {
        return ['search' => ['required', 'string', 'min:2', 'max:80']];
    }

    public function requiredAbility(): Ability
    {
        return Ability::CatalogueView;
    }

    public function handle(array $input, AiContext $context): array
    {
        $search = trim((string) $input['search']);
        $words = array_slice(array_filter(preg_split('/\s+/', mb_strtolower($search)) ?: []), 0, 5);
        $barcodeIds = ProductBarcode::query()->where('barcode', $search)->limit(10)->pluck('product_id')->all();

        $products = Product::query()
            ->where(fn (Builder $q) => $q->where('is_active', true)->orWhereNull('is_active'))
            ->where(fn (Builder $q) => $q
                ->where(function (Builder $w) use ($words) {
                    foreach ($words as $word) {
                        $w->whereRaw('LOWER(name) LIKE ?', ['%'.addcslashes($word, '%_\\').'%']);
                    }
                })
                ->orWhere('sku', 'like', addcslashes($search, '%_\\').'%')
                ->orWhereIn('id', $barcodeIds))
            ->orderBy('name')->limit(10)
            ->get(['id', 'name', 'sku', 'sell_price', 'cost_price', 'vat_rate_id']);

        $links = ProductSupplier::query()->whereIn('product_id', $products->pluck('id'))->get();
        $suppliers = Supplier::query()->whereIn('id', $links->pluck('supplier_id')->unique())->pluck('name', 'id');

        $this->links->add('Products matching "'.mb_substr($search, 0, 40).'"', '/app/products', ['search' => $search], ShopPin::resolve(null));

        return [
            'search' => $search,
            'products' => $products->map(fn (Product $p) => [
                'productId' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku ?: null,
                'sellPrice' => Money::normalise($p->sell_price ?? '0'),
                'costPrice' => Money::normalise($p->cost_price ?? '0', 4),
                'suppliers' => $links->where('product_id', $p->id)->map(fn (ProductSupplier $s) => [
                    'supplierId' => $s->supplier_id,
                    'supplier' => $suppliers[$s->supplier_id] ?? 'Unknown supplier',
                    'caseQty' => max(1, (int) $s->case_qty),
                    'caseCost' => Money::normalise($s->case_cost ?? '0', 4),
                    'preferred' => (bool) $s->is_preferred,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
