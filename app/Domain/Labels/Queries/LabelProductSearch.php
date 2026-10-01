<?php

namespace App\Domain\Labels\Queries;

use App\Domain\Labels\Models\LabelQueueItem;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductBarcode;
use Illuminate\Database\Eloquent\Builder;

/**
 * Products to add to a shop's label queue by hand (gap #6): active products whose name or code contains the text, or
 * whose barcode is exactly it (a scanned barcode). At most 20, with whether each is already waiting in that shop.
 */
final class LabelProductSearch
{
    /**
     * @return list<array{id: string, name: string, sku: string|null, barcode: string|null, price: string, waiting: bool}>
     */
    public static function for(Branch $shop, string $search): array
    {
        $search = trim($search);

        if (mb_strlen($search) < 2) {
            return [];
        }

        $products = Product::query()->where('is_active', true)
            ->where(fn (Builder $q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('sku', 'like', $search.'%')
                ->orWhereIn('id', ProductBarcode::query()->select('product_id')->where('barcode', $search)))
            ->orderBy('name')->limit(20)->get(['id', 'name', 'sku', 'sell_price']);
        $ids = $products->pluck('id')->all();
        $barcodes = ProductBarcode::query()->whereIn('product_id', $ids)->orderByDesc('is_primary')->orderBy('id')->get(['product_id', 'barcode'])
            ->groupBy('product_id')->map(fn ($g) => (string) $g->first()->barcode);
        $waiting = LabelQueueItem::query()->where('branch_id', $shop->id)->where('pending', true)->whereIn('product_id', $ids)->pluck('product_id')->flip();

        return $products->map(fn (Product $p) => [
            'id' => $p->id, 'name' => (string) $p->name, 'sku' => $p->sku, 'barcode' => $barcodes[$p->id] ?? null,
            'price' => (string) $p->sell_price, 'waiting' => $waiting->has($p->id),
        ])->values()->all();
    }
}
