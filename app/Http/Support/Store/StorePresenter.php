<?php

namespace App\Http\Support\Store;

use App\Domain\OnlineStore\Basket\BasketRules;
use App\Domain\OnlineStore\Basket\PricedBasket;
use App\Domain\OnlineStore\Data\CatalogueItem;
use App\Domain\OnlineStore\Enums\StoreSection;
use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Queries\StoreOffers;
use App\Domain\OnlineStore\Queries\StorePhotos;
use App\Domain\OnlineStore\Support\AddressLine;
use App\Domain\OnlineStore\Support\DisplayName;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Domain\OnlineStore\Support\PackSizeLabel;
use App\Domain\OnlineStore\Support\ShopOpenNow;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * What the public store's pages show (OS-2), as plain arrays for the React pages. Product photos (StorePhotos, OS-8):
 * the online photo (`online_store_products.photo_path` on the public disk), else the master catalogue's picture of
 * one of its barcodes; the till's own `image_path` is a path on the till's PC, never a URL (contract PORTAL-CHANGES
 * 0.1.35). No photo → the page draws a branded placeholder. With the shop, a product carries its till offer's label
 * (StoreOffers, OS-8) to show beside the shelf price.
 */
final class StorePresenter
{
    /**
     * @param  list<CatalogueItem>  $items
     * @return list<array<string, mixed>>
     */
    public static function products(OnlineStore $store, array $items, ?OnlineStoreShop $shop = null): array
    {
        $photos = self::photos($store, array_map(fn (CatalogueItem $item) => $item->id, $items));
        $offers = $shop === null ? [] : app(StoreOffers::class)->labels($store, $shop, $items);

        return array_map(fn (CatalogueItem $item) => self::product($item, $photos[$item->id] ?? null, $offers[$item->id] ?? null), $items);
    }

    /**
     * @param  string|null  $photoUrl  the photo's address (StorePhotos)
     * @return array<string, mixed>
     */
    public static function product(CatalogueItem $item, ?string $photoUrl, ?string $offer = null): array
    {
        return [
            'id' => $item->id,
            // OS-11: a till name in capitals shows in Title Case (DisplayName); the stored name is unchanged.
            'name' => DisplayName::of($item->name),
            'description' => $item->description,
            'photoUrl' => $photoUrl,
            'offer' => $offer,
            'size' => PackSizeLabel::size($item),
            'unitPrice' => PackSizeLabel::unitPrice($item),
            'departmentId' => $item->departmentId,
            'price' => $item->price,
            // OS-11 (audit OSA-31): a deposit return item's deposit, charged by the till at collection.
            'deposit' => $item->deposit,
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
            'name' => $branch === null ? 'The shop' : DisplayName::of((string) $branch->name),
            // OS-11: one clean line, a town or postcode already in the address is not repeated (AddressLine).
            'address' => $branch === null ? '' : (string) AddressLine::from($branch->address, $branch->town, $branch->postcode),
            'phone' => $branch?->phone ? trim($branch->phone) : null,
            'prepMinutes' => $shop->prep_minutes,
            'hours' => array_map(fn (string $day) => ['day' => ucfirst($day), 'hours' => $shop->hoursOn($day)], OnlineStoreShop::WEEKDAYS),
            // OS-11: today's weekday in shop time, "open now", and the shop's local delivery (areas and fees) when it delivers.
            'today' => CarbonImmutable::now(OnlineStoreSwitch::timezone())->format('l'),
            'openNow' => ShopOpenNow::describe($shop),
            'delivery' => ! $shop->delivery_enabled ? null : [
                'areas' => $shop->delivery_areas ?? [],
                'fee' => Money::normalise($shop->delivery_fee ?? '0', 2),
                'freeOver' => $shop->delivery_free_over === null ? null : Money::normalise($shop->delivery_free_over, 2),
                'minimum' => $shop->delivery_minimum === null ? null : Money::normalise($shop->delivery_minimum, 2),
            ],
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
     * The photos of these products, by product id (StorePhotos: online photo, else the master catalogue's).
     *
     * @param  list<string>  $productIds
     * @return array<string, string>
     */
    private static function photos(OnlineStore $store, array $productIds): array
    {
        return app(StorePhotos::class)->products($store, $productIds);
    }
}
