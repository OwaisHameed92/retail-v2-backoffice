<?php

namespace App\Domain\MasterCatalogue\Queries;

use App\Domain\Catalogue\Queries\CatalogueOptions;
use App\Domain\MasterCatalogue\Models\MasterProduct;
use App\Domain\MasterCatalogue\Support\PackSize;
use App\Domain\TillData\Enums\AgeRule;

/**
 * The admin master product form: the values, the barcodes merged into it, other products with the same name (merge
 * candidates), and the pick lists (units, age checks, departments and categories already used).
 */
final class MasterProductForm
{
    /**
     * @return array<string, mixed>
     */
    public static function for(?MasterProduct $product): array
    {
        return [
            'product' => $product === null ? null : [
                ...MasterRow::of($product),
                'mergedInto' => $product->merged_into_id === null ? null : MasterProduct::query()->find($product->merged_into_id, ['id', 'name', 'barcode'])?->only(['id', 'name', 'barcode']),
                'aliases' => MasterProduct::query()->where('merged_into_id', $product->id)->orderBy('barcode')->pluck('barcode')->all(),
                'sameName' => MasterProduct::query()->current()->whereKeyNot($product->id)
                    ->whereRaw('lower(name) = ?', [mb_strtolower($product->name)])->limit(10)->get()
                    ->map(fn (MasterProduct $p) => MasterRow::of($p))->all(),
            ],
            'values' => [
                'barcode' => $product->barcode ?? '',
                'name' => $product->name ?? '',
                'brand' => $product->brand ?? '',
                'size_value' => $product?->size_value === null ? '' : PackSize::trim((string) $product->size_value),
                'size_unit' => $product->size_unit ?? '',
                'pack_qty' => $product?->pack_qty === null ? '' : (string) $product->pack_qty,
                'department' => $product->department ?? '',
                'category' => $product->category ?? '',
                'vat_rate' => $product?->vat_rate === null ? '' : PackSize::trim((string) $product->vat_rate),
                'rrp' => $product?->rrp === null ? '' : (string) $product->rrp,
                'age_rule' => $product->age_rule ?? 'none',
                'image_url' => $product->image_url ?? '',
                'in_starter_packs' => $product->in_starter_packs ?? false,
            ],
            'options' => self::options(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        return [
            'units' => array_map(fn (string $u) => ['value' => $u, 'label' => $u], PackSize::UNITS),
            'ageRules' => array_map(fn (AgeRule $r) => ['value' => $r->value, 'label' => CatalogueOptions::ageRule($r)], AgeRule::cases()),
            'departments' => array_column(MasterRow::departments(), 'value'),
            'categories' => MasterProduct::query()->whereNotNull('category')->distinct()->orderBy('category')->limit(500)->pluck('category')->all(),
            'vatRates' => [['value' => '20', 'label' => 'Standard 20%'], ['value' => '5', 'label' => 'Reduced 5%'], ['value' => '0', 'label' => 'Zero 0%']],
        ];
    }
}
