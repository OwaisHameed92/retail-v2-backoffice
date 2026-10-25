<?php

namespace App\Http\Support\Store;

use App\Domain\OnlineStore\Data\CatalogueFilters;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Queries\FeaturedProducts;
use App\Domain\OnlineStore\Queries\StoreAisles;
use App\Domain\OnlineStore\Queries\StoreBanners;
use App\Domain\OnlineStore\Queries\StorefrontCatalogue;
use App\Domain\OnlineStore\Queries\StoreOffers;
use App\Domain\OnlineStore\Queries\StorePhotos;

/**
 * The public home page's rows (OS-8), as the React page draws them: banners (slides), featured products (best
 * sellers, hand-picked or newest), the "On offer" row, a row for each of the first six aisles shown at the shop
 * (OS-12: the same aisles as the header and the tiles, in the store's order) and the aisle tiles with a photo each. No HFSS product in any home
 * row (OS-11, audit OSA-31, HomeRowRules).
 */
final class StoreHomeRows
{
    public const FEATURED = 12;

    public const OFFERS = 12;

    public const PER_DEPARTMENT = 10;

    /** OS-12: a row for each of the first this many aisles (the old picker's maximum). */
    public const DEPARTMENT_ROWS = 6;

    public function __construct(
        private readonly StorefrontCatalogue $catalogue,
        private readonly FeaturedProducts $featured,
        private readonly StoreOffers $offers,
        private readonly StorePhotos $photos,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function banners(StoreContext $context): array
    {
        return StoreBanners::slides($context->store, array_column($this->departments($context), 'id'));
    }

    /**
     * @return array{featured: list<array<string, mixed>>, featuredMode: string}
     */
    public function featured(StoreContext $context): array
    {
        $shop = $context->shop();

        if ($shop === null) {
            return ['featured' => [], 'featuredMode' => 'bestSellers'];
        }

        $branchIds = $context->shops->map(fn (OnlineStoreShop $s) => $s->branch_id)->values()->all();
        $row = $this->featured->handle($context->store, $shop, $branchIds, self::FEATURED);

        return ['featured' => StorePresenter::products($context->store, $row['items'], $shop), 'featuredMode' => $row['mode']->value];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function offers(StoreContext $context): array
    {
        $shop = $context->shop();

        return $shop === null ? [] : StorePresenter::products($context->store, $this->offers->products($context->store, $shop, self::OFFERS), $shop);
    }

    /**
     * @return list<array{id: string, name: string, count: int, url: string, products: list<array<string, mixed>>}>
     */
    public function departmentRows(StoreContext $context): array
    {
        $shop = $context->shop();
        $shown = collect($this->departments($context))->keyBy('id');

        if ($shop === null || $shown->isEmpty()) {
            return [];
        }

        // OS-12: one choice: the aisles shown, in the store's order (StoreAisles; old home-row picks are its fallback).
        $ids = $shown->keys()->take(self::DEPARTMENT_ROWS)->all();
        $rows = [];

        foreach ($ids as $id) {
            $aisle = StoreContext::aisleFilter($shown[$id]);
            $items = $this->catalogue->products($context->store, $shop, departmentId: $aisle['departmentId'], perPage: self::PER_DEPARTMENT,
                filters: new CatalogueFilters(inStockOnly: true, homeRow: true), categoryId: $aisle['categoryId'])->items;

            if ($items !== []) {
                $department = $shown[$id];
                $rows[] = [
                    'id' => $department['id'], 'name' => $department['name'], 'count' => $department['count'],
                    'url' => route('store.department', ['department' => $department['id']], false),
                    'products' => StorePresenter::products($context->store, $items, $shop),
                ];
            }
        }

        return $rows;
    }

    /**
     * The departments shown at the shop with a photo each (null: the page draws the department's icon).
     *
     * @return list<array{id: string, name: string, count: int, url: string, photoUrl: string|null}>
     */
    public function departmentTiles(StoreContext $context): array
    {
        $shop = $context->shop();
        $departments = $this->departments($context);

        // OS-11: the big aisle tiles only when there are enough real aisles to be worth it.
        if ($shop === null || count($departments) < StoreAisles::GOOD) {
            return [];
        }

        $need = array_values(array_filter($departments, fn (array $d) => $d['photoUrl'] === null));
        $photos = $need === [] ? [] : $this->photos->departments($context->store, $shop, array_column($need, 'id'), StoreContext::aisleColumn($need[0]));

        return array_map(fn (array $d) => [
            'id' => $d['id'], 'name' => $d['name'], 'count' => $d['count'],
            'url' => route('store.department', ['department' => $d['id']], false),
            'photoUrl' => $d['photoUrl'] ?? $photos[$d['id']] ?? null,
        ], $departments);
    }

    /**
     * @return list<array{id: string, kind: string, name: string, tillName: string, count: int, photoUrl: string|null}>
     */
    private function departments(StoreContext $context): array
    {
        return $context->departments();
    }
}
