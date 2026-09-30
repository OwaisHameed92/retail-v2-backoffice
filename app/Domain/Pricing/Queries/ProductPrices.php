<?php

namespace App\Domain\Pricing\Queries;

use App\Domain\Pricing\Support\ShopPrices;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\TillData\Models\BranchPrice;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductUnit;
use App\Domain\TillData\Models\Unit;
use Carbon\CarbonImmutable;

/**
 * Props for one product's prices (module 4.3): the business price, each shop's price now (own or business), what is
 * scheduled, and the history of every shop price row (newest first). A one-shop user sees only their shop and may
 * change only its price; the business price ("every shop") is read-only for them.
 */
final class ProductPrices
{
    private const HISTORY_LIMIT = 200;

    /**
     * @return array<string, mixed>
     */
    public static function for(Product $product): array
    {
        $tenancy = app(CurrentCompany::class);
        $now = CarbonImmutable::now('UTC');
        $shops = ShopPrices::shops();
        $shopIds = $shops->pluck('id')->all();
        $shopNames = $shops->pluck('name', 'id')->all();
        $unitNames = Unit::query()->pluck('name', 'id')->all();
        $units = ProductUnit::query()->where('product_id', $product->id)->orderBy('position')->get()
            ->map(fn (ProductUnit $u) => ['id' => $u->id, 'name' => (string) ($unitNames[$u->unit_id] ?? 'Unit'), 'sellPrice' => (string) $u->sell_price_inc_vat])
            ->values()->all();
        $unitLabel = array_column($units, 'name', 'id');
        $live = ShopPrices::live([$product->id], $shopIds, $now)[$product->id] ?? [];

        $rows = BranchPrice::query()->where('product_id', $product->id)->whereIn('branch_id', $shopIds)
            ->orderByDesc('valid_from_utc')->orderByDesc('id')->limit(self::HISTORY_LIMIT)->get();
        $map = fn (BranchPrice $row) => ShopPrices::row($row, $shopNames[$row->branch_id] ?? null, $unitLabel[$row->product_unit_id ?? ''] ?? null);

        return [
            'product' => [
                'id' => $product->id, 'name' => (string) $product->name, 'sku' => $product->sku, 'sellPrice' => (string) $product->sell_price,
                'costPrice' => (string) $product->cost_price, 'isActive' => $product->is_active,
            ],
            'units' => $units,
            'shops' => $shops->map(fn ($shop) => [
                'id' => $shop->id, 'name' => $shop->name, 'code' => $shop->code, 'isActive' => (bool) $shop->is_active,
                'current' => isset($live[$shop->id]['']) ? $map($live[$shop->id]['']) : null,
                'unitPrices' => collect($live[$shop->id] ?? [])->except([''])->map($map)->values()->all(),
                'scheduled' => $rows->where('branch_id', $shop->id)->filter(fn (BranchPrice $r) => ShopPrices::status($r, $now) === 'scheduled')
                    ->sortBy(fn (BranchPrice $r) => $r->valid_from_utc->getTimestamp())->map($map)->values()->all(),
            ])->values()->all(),
            'history' => $rows->map($map)->values()->all(),
            'historyLimited' => $rows->count() === self::HISTORY_LIMIT,
            'restrictedShop' => $tenancy->restrictedBranchId(),
            'canSetShopPrices' => $tenancy->can(Ability::PricesManage),
            'canSetEveryShop' => $tenancy->can(Ability::PricesManage) && $tenancy->restrictedBranchId() === null,
        ];
    }
}
