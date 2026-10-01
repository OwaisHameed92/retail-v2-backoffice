<?php

namespace App\Domain\MasterCatalogue\Queries;

use App\Domain\Catalogue\Queries\CatalogueOptions;
use App\Domain\MasterCatalogue\Enums\StarterPack;
use App\Domain\MasterCatalogue\Models\MasterProduct;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\Product;

/**
 * The onboarding starter pack screen (/app/products/starter): the kinds of shop, the one suggested from the business
 * type, the catalogue's starter departments (with counts, and which the chosen pack ticks), how many starter products
 * the business already has, and its own departments for the mapping step.
 */
final class StarterPackPage
{
    /**
     * @return array<string, mixed>
     */
    public static function for(?string $requested, CurrentCompany $tenancy): array
    {
        $suggested = StarterPack::forBusiness($tenancy->require()->business_type);
        $pack = StarterPack::tryFrom((string) $requested) ?? $suggested;
        $included = $pack->departments();
        $options = CatalogueOptions::all();
        $owned = 0;

        MasterProduct::query()->current()->where('in_starter_packs', true)->select(['id', 'barcode'])
            ->chunkById(1000, function ($rows) use (&$owned) {
                $owned += count(CatalogueSearch::owned($rows->pluck('barcode')->map(fn ($b) => (string) $b)->values()->all()));
            });

        return [
            'packs' => StarterPack::options(),
            'pack' => $pack->value,
            'suggested' => $suggested->value,
            'departments' => array_map(fn (array $d) => [...$d, 'included' => $included === null || in_array($d['value'], $included, true)], MasterRow::departments(true)),
            'owned' => $owned,
            'productCount' => Product::query()->count(),
            'yourDepartments' => $options['departments'],
            'hasVatRates' => $options['vatRates'] !== [],
        ];
    }
}
