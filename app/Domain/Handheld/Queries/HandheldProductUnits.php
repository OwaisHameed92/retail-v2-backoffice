<?php

namespace App\Domain\Handheld\Queries;

use App\Domain\Handheld\Support\ShopProductFacts;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Enums\UnitType;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductBarcode;
use App\Domain\TillData\Models\ProductUnit;
use App\Domain\TillData\Models\Unit;
use Illuminate\Support\Collection;

/**
 * Units a product is sold and bought in, in the legacy multi-unit shape (`GET /api/pda/products/{id}/units`) and
 * barcode scans (`GET /api/pda/resolve-barcode`). The base unit (the product's own `unit_code`, conversion 1) has no
 * `product_unit_id`; extra units are the product's `ProductUnit` rows (conversion = how many base units). A barcode
 * points at a unit (`product_unit_id`), a pack of the base unit (`pack_qty` > 1, priced as that many base units), or
 * the base unit. Prices are the shop's (its own live price for that unit, else the business price).
 */
final class HandheldProductUnits
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forProduct(Product $product, Branch $shop): array
    {
        $facts = new ShopProductFacts($shop, [$product->id]);
        $units = $this->units($product);
        $codes = $this->unitCodes();

        return [
            $this->base($product, $facts, $codes),
            ...$units->map(fn (ProductUnit $unit) => $this->unit($product, $unit, $facts, $codes))->values()->all(),
        ];
    }

    /**
     * Every active product whose barcode is exactly this one (a barcode may sit on more than one product).
     *
     * @return list<array<string, mixed>>
     */
    public function forBarcode(string $barcode, Branch $shop): array
    {
        $rows = ProductBarcode::query()->where('barcode', trim($barcode))
            ->whereIn('product_id', Product::query()->select('id')->where('is_active', true))
            ->orderByDesc('is_primary')->orderBy('product_id')->get();
        $products = Product::query()->whereKey($rows->pluck('product_id')->unique()->all())->get()->keyBy('id');
        $facts = new ShopProductFacts($shop, $products->keys()->all());
        $codes = $this->unitCodes();
        $matches = [];

        foreach ($rows as $row) {
            $product = $products[$row->product_id] ?? null;

            if (! $product instanceof Product) {
                continue;
            }

            $units = $this->units($product);
            $unit = $row->product_unit_id === null ? null : $units->firstWhere('id', $row->product_unit_id);
            $match = match (true) {
                $unit instanceof ProductUnit => $this->unit($product, $unit, $facts, $codes),
                $row->pack_qty > 1 => $this->pack($product, $row, $facts, $codes),
                default => $this->base($product, $facts, $codes),
            };

            $matches[] = [...$match, 'matched_barcode' => $row->barcode, 'is_primary' => $row->is_primary];
        }

        return $matches;
    }

    /**
     * @return Collection<int, ProductUnit>
     */
    private function units(Product $product): Collection
    {
        return ProductUnit::query()->where('product_id', $product->id)->orderBy('position')->orderBy('id')->get();
    }

    /**
     * The business's units by id and by code (the base unit is found by its code).
     *
     * @return array{byId: Collection<string, Unit>, byCode: Collection<string, Unit>}
     */
    private function unitCodes(): array
    {
        $all = Unit::query()->get(['id', 'code', 'name', 'decimals_allowed', 'decimal_places']);

        return ['byId' => $all->keyBy('id'), 'byCode' => $all->keyBy(fn (Unit $u) => mb_strtoupper($u->code))];
    }

    /**
     * @param  array{byId: Collection<string, Unit>, byCode: Collection<string, Unit>}  $codes
     * @return array<string, mixed>
     */
    private function base(Product $product, ShopProductFacts $facts, array $codes): array
    {
        $unit = $codes['byCode'][mb_strtoupper($product->unit_code)] ?? null;
        $weighed = $product->unit_type === UnitType::Kg;

        return $this->shape($product, null, $unit?->id, $product->unit_code, $unit !== null ? $unit->name : $product->unit_code, '1.0000',
            $facts->retail($product), Money::normalise($product->cost_price, 4), true, $weighed || ($unit !== null && $unit->decimals_allowed),
            $weighed ? max(3, $unit !== null ? $unit->decimal_places : 0) : ($unit !== null ? $unit->decimal_places : 0));
    }

    /**
     * @param  array{byId: Collection<string, Unit>, byCode: Collection<string, Unit>}  $codes
     * @return array<string, mixed>
     */
    private function unit(Product $product, ProductUnit $row, ShopProductFacts $facts, array $codes): array
    {
        $unit = $codes['byId'][$row->unit_id] ?? null;

        return $this->shape($product, $row->id, $row->unit_id, $unit !== null ? $unit->code : '', $unit !== null ? $unit->name : '', Money::normalise($row->conversion_factor, 4),
            $facts->unitRetail($product, $row->id, (string) $row->sell_price_inc_vat), Money::normalise($row->cost, 4), false,
            $unit !== null && $unit->decimals_allowed, $unit !== null ? $unit->decimal_places : 0);
    }

    /**
     * @param  array{byId: Collection<string, Unit>, byCode: Collection<string, Unit>}  $codes
     * @return array<string, mixed>
     */
    private function pack(Product $product, ProductBarcode $row, ShopProductFacts $facts, array $codes): array
    {
        $base = $this->base($product, $facts, $codes);
        $code = $row->unit_code !== null && $row->unit_code !== '' ? $row->unit_code : 'PACK';

        return [...$base, 'selected_unit_id' => null, 'selected_unit_code' => $code, 'selected_unit_name' => "Pack of {$row->pack_qty}",
            'conversion_to_base' => Money::normalise($row->pack_qty, 4), 'retail_price' => Money::mul($base['retail_price'], $row->pack_qty),
            'purchase_cost' => Money::mul($base['purchase_cost'], $row->pack_qty, 4), 'is_base_unit' => false, 'allow_decimal' => false, 'decimal_places' => 0];
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(Product $product, ?string $productUnitId, ?string $unitId, string $code, string $name, string $conversion,
        string $retail, string $cost, bool $isBase, bool $decimals, int $places): array
    {
        return [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_unit_id' => $productUnitId,
            'selected_unit_id' => $unitId,
            'selected_unit_code' => $code,
            'selected_unit_name' => $name,
            'conversion_to_base' => $conversion,
            'retail_price' => $retail,
            'purchase_cost' => $cost,
            'is_base_unit' => $isBase,
            'allow_decimal' => $decimals,
            'decimal_places' => $places,
        ];
    }
}
