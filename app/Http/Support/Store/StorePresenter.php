<?php

namespace App\Http\Support\Store;

use App\Domain\OnlineStore\Basket\BasketRules;
use App\Domain\OnlineStore\Basket\PricedBasket;
use App\Domain\OnlineStore\Data\CatalogueItem;
use App\Domain\OnlineStore\Enums\StoreSection;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreProduct;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Support\StoreScope;
use App\Domain\Tenancy\Models\Branch;
use Illuminate\Support\Facades\Storage;

/**
 * What the public store's pages show (OS-2), as plain arrays for the React pages. Product photos are only the
 * online photos (`online_store_products.photo_path` on the public disk): the till's own `image_path` is a path on
 * the till's PC, never a URL (contract PORTAL-CHANGES 0.1.35). No photo → the page draws a placeholder tile.
 */
final class StorePresenter
{
    /**
     * @param  list<CatalogueItem>  $items
     * @return list<array<string, mixed>>
     */
    public static function products(OnlineStore $store, array $items): array
    {
        $photos = self::photos($store, array_map(fn (CatalogueItem $item) => $item->id, $items));

        return array_map(fn (CatalogueItem $item) => self::product($item, $photos[$item->id] ?? null), $items);
    }

    /**
     * @return array<string, mixed>
     */
    public static function product(CatalogueItem $item, ?string $photoPath): array
    {
        return [
            'id' => $item->id,
            'name' => $item->name,
            'description' => $item->description,
            'photoUrl' => self::publicUrl($photoPath),
            'departmentId' => $item->departmentId,
            'price' => $item->price,
            'isWeighed' => $item->isWeighed,
            'step' => BasketRules::display(BasketRules::step($item)),
            'max' => BasketRules::display(BasketRules::limit($item)),
            'orderable' => $item->orderable,
            'url' => route('store.product', ['product' => $item->id], false),
        ];
    }

    /**
     * The basket's lines for the basket and checkout pages.
     *
     * @return list<array<string, mixed>>
     */
    public static function basketLines(OnlineStore $store, PricedBasket $basket): array
    {
        $photos = self::photos($store, array_map(fn (array $line) => $line['item']->id, $basket->lines));

        return array_map(fn (array $line) => [
            'product' => self::product($line['item'], $photos[$line['item']->id] ?? null),
            'quantity' => BasketRules::display($line['quantity']),
            'lineTotal' => $line['lineTotal'],
            'updateUrl' => route('store.basket.update', ['product' => $line['item']->id], false),
            'removeUrl' => route('store.basket.remove', ['product' => $line['item']->id], false),
        ], $basket->lines);
    }

    /**
     * @return array{count: int, total: string, estimated: bool, notices: list<string>}
     */
    public static function basketSummary(PricedBasket $basket): array
    {
        return ['count' => $basket->count(), 'total' => $basket->total, 'estimated' => $basket->estimated(), 'notices' => $basket->notices];
    }

    /**
     * A collection shop with its address and hours (shop time).
     *
     * @return array<string, mixed>
     */
    public static function shop(OnlineStoreShop $shop): array
    {
        $branch = $shop->relationLoaded('branch') ? $shop->getRelation('branch') : null;
        $branch = $branch instanceof Branch ? $branch : null;

        return [
            'id' => $shop->id,
            'name' => $branch === null ? 'The shop' : $branch->name,
            'address' => $branch === null ? '' : implode(', ', array_values(array_filter([trim((string) $branch->address), trim((string) $branch->town), trim((string) $branch->postcode)]))),
            'phone' => $branch?->phone ? trim($branch->phone) : null,
            'prepMinutes' => $shop->prep_minutes,
            'hours' => array_map(fn (string $day) => ['day' => ucfirst($day), 'hours' => $shop->hoursOn($day)], OnlineStoreShop::WEEKDAYS),
        ];
    }

    /**
     * The home page's sections, in the store's order, only those turned on (StoreSection).
     *
     * @return list<string>
     */
    public static function sections(OnlineStore $store): array
    {
        $saved = is_array($store->sections) && $store->sections !== [] ? $store->sections : StoreSection::defaults();
        $keys = [];

        foreach ($saved as $section) {
            $key = StoreSection::tryFrom($section['key']);

            if ($key !== null && $section['enabled'] && ! in_array($key->value, $keys, true)) {
                $keys[] = $key->value;
            }
        }

        return $keys;
    }

    /** "#0B4A34": the store's colour when it is a proper hex colour, else its template's (forest green, oxblood). */
    public static function brand(OnlineStore $store): string
    {
        $colour = (string) $store->brand_colour;

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $colour) === 1 ? strtoupper($colour) : $store->template->defaultBrand();
    }

    /** The store's own banner photo, else its template's. */
    public static function bannerUrl(OnlineStore $store): string
    {
        return self::publicUrl($store->banner_path) ?? asset($store->template->bannerPath());
    }

    /** "KC" for "Kirkgate Convenience". */
    public static function initials(string $name): string
    {
        $words = preg_split('/[^\pL\pN]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: ['S'];
        $initials = mb_substr($words[0], 0, 1).(isset($words[1]) ? mb_substr($words[1], 0, 1) : '');

        return mb_strtoupper($initials);
    }

    /** A file on the public disk (paths written by the store builder), or null. */
    public static function publicUrl(?string $path): ?string
    {
        $path = trim((string) $path);

        return $path === '' || str_contains($path, '..') ? null : Storage::disk('public')->url($path);
    }

    /**
     * The online photos of these products, by product id (one query, scoped to the store's business).
     *
     * @param  list<string>  $productIds
     * @return array<string, string>
     */
    private static function photos(OnlineStore $store, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return StoreScope::run($store, fn (): array => OnlineStoreProduct::query()
            ->where('company_id', $store->company_id)
            ->whereIn('product_id', $productIds)
            ->whereNotNull('photo_path')->where('photo_path', '!=', '')
            ->pluck('photo_path', 'product_id')
            ->map(fn ($path) => (string) $path)
            ->all());
    }
}
