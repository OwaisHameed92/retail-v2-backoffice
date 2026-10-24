<?php

namespace App\Domain\OnlineStore\Queries;

use App\Domain\OnlineStore\Data\CatalogueItem;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Department;

/**
 * The store builder's product picker (OS-8: a banner's button, hand-picked featured products): up to
 * {@see self::LIMIT} products the store shows at its first collection shop, matching the words as the public search
 * does (name, brand, code, barcode), with price, department and photo. No store or no collection shop yet → none.
 */
final class StoreProductSearch
{
    public const LIMIT = 20;

    public function __construct(private readonly StorefrontCatalogue $catalogue, private readonly StorefrontShops $shops, private readonly StorePhotos $photos) {}

    /**
     * @return list<array{id: string, name: string, price: string, isWeighed: bool, department: string|null, photoUrl: string|null}>
     */
    public function handle(Company $company, string $term): array
    {
        $store = OnlineStore::query()->where('company_id', $company->id)->first();
        $shop = $store === null ? null : $this->shops->handle($store->setRelation('company', $company))->first();

        if (! $store instanceof OnlineStore || ! $shop instanceof OnlineStoreShop) {
            return [];
        }

        $items = $this->catalogue->products($store, $shop, search: $term === '' ? null : $term, perPage: self::LIMIT)->items;
        $photos = $this->photos->products($store, array_map(fn (CatalogueItem $i) => $i->id, $items));
        $departments = Department::query()->where('company_id', $company->id)
            ->whereIn('id', array_values(array_filter(array_map(fn (CatalogueItem $i) => $i->departmentId, $items))))->pluck('name', 'id');

        return array_map(fn (CatalogueItem $i) => [
            'id' => $i->id,
            'name' => $i->name,
            'price' => $i->price,
            'isWeighed' => $i->isWeighed,
            'department' => $i->departmentId === null ? null : ($departments[$i->departmentId] ?? null),
            'photoUrl' => $photos[$i->id] ?? null,
        ], $items);
    }
}
