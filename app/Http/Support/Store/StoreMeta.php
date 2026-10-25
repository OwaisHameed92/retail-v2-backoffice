<?php

namespace App\Http\Support\Store;

use App\Domain\Shared\Country\Country;

/**
 * The public store's server-side `<head>` (OS-8, audits OSA-9 and OSA-17; no SSR), from the Inertia page the Blade
 * view renders: title, meta description, Open Graph / Twitter image, canonical address, robots and JSON-LD.
 *
 * - Indexed: home, a department's first page without filters, and products.
 * - `noindex`: search, filtered or later department pages (`noindex, follow`), and the basket, checkout, order and
 *   error pages (`noindex, nofollow`; the order page shows the customer's details).
 * - JSON-LD: the shop (`GroceryStore` or `ButcherShop`, address, phone, collection hours) on home; `Product` with
 *   price and availability on product pages.
 */
final class StoreMeta
{
    /** Pages never indexed, nor their links followed. */
    public const PRIVATE_COMPONENTS = ['store/basket', 'store/checkout', 'store/order', 'store/error'];

    /** List filters that make a department page a duplicate. */
    private const FILTERS = ['sort', 'stock', 'min', 'max', 'page', 'q'];

    /**
     * @param  array<string, mixed>  $page  the Inertia page (component, props, url)
     * @return array{title: string, description: string, image: string|null, canonical: string|null, robots: string, siteName: string, type: string, jsonLd: list<array<string, mixed>>}
     */
    public static function for(array $page): array
    {
        $component = (string) ($page['component'] ?? '');
        $props = is_array($page['props'] ?? null) ? $page['props'] : [];
        $store = is_array($props['store'] ?? null) ? $props['store'] : null;
        $name = (string) ($store['name'] ?? 'Online store');
        $shop = is_array($store['shop'] ?? null) ? $store['shop'] : null;
        $where = $shop === null ? '' : ' from '.$shop['name'];
        // OS-12: the words follow the store's methods (StoreWording): collection, delivery or both.
        $mode = (string) ($store['methods']['mode'] ?? 'collection');
        $default = match ($mode) {
            'delivery' => "Order online from {$name} and have it delivered to your door. Shelf prices and stock live from the shop.",
            'both' => "Order online from {$name}: collect{$where} or have it delivered. Shelf prices and stock live from the shop.",
            default => "Order online from {$name} and collect{$where}. Shelf prices and stock live from the shop, pay when you collect.",
        };
        $meta = ['title' => $name, 'description' => $default, 'image' => null, 'canonical' => null, 'robots' => self::robots($component, (string) ($page['url'] ?? '')),
            'siteName' => $name, 'type' => 'website', 'jsonLd' => []];

        if ($store === null) {
            return $meta;
        }

        return match ($component) {
            'store/home' => self::home($meta, $props, $store, $mode),
            'store/department' => self::department($meta, $props, $name),
            'store/product' => self::product($meta, $props, $name, $mode),
            'store/search' => [...$meta, 'title' => 'Search · '.$name],
            default => $meta,
        };
    }

    /** The robots value for a page (also sent as the `X-Robots-Tag` header by ResolveOnlineStore). */
    public static function robots(string $component, string $url): string
    {
        if (in_array($component, self::PRIVATE_COMPONENTS, true)) {
            return 'noindex, nofollow';
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $filtered = array_intersect(array_keys($query), self::FILTERS) !== [];

        return $component === 'store/search' || $filtered ? 'noindex, follow' : 'index, follow';
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $props
     * @param  array<string, mixed>  $store
     * @return array<string, mixed>
     */
    private static function home(array $meta, array $props, array $store, string $mode): array
    {
        $banner = is_array($props['banners'][0] ?? null) ? $props['banners'][0] : [];
        $about = trim((string) ($props['aboutText'] ?? ''));
        $shop = is_array($props['shops'][0] ?? null) ? $props['shops'][0] : null;
        $business = array_filter([
            '@context' => 'https://schema.org',
            '@type' => ($store['template'] ?? '') === 'butcher' ? 'ButcherShop' : 'GroceryStore',
            'name' => $store['name'],
            'url' => url((string) $store['urls']['home']),
            'image' => self::absolute($banner['photoUrl'] ?? null),
            'logo' => self::absolute($store['logoUrl'] ?? null),
            'telephone' => $shop['phone'] ?? null,
            'address' => $shop === null || $shop['address'] === '' ? null : ['@type' => 'PostalAddress', 'streetAddress' => $shop['address']],
            'openingHoursSpecification' => $shop === null || ($shop['collects'] ?? true) === false ? null : array_values(array_filter(array_map(fn (array $d) => $d['hours'] === null ? null : [
                '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'https://schema.org/'.$d['day'],
                'opens' => $d['hours']['open'], 'closes' => $d['hours']['close'],
            ], (array) $shop['hours']))),
        ], fn ($v) => $v !== null && $v !== []);

        return [...$meta,
            'title' => $store['name'].' · Order online, '.match ($mode) {
                'delivery' => 'local delivery',
                'both' => 'click & collect or delivery',
                default => 'click & collect',
            },
            'description' => self::trim($about !== '' ? $about : ((string) ($banner['text'] ?? '') ?: $meta['description'])),
            'image' => self::absolute($banner['photoUrl'] ?? null),
            'canonical' => url((string) $store['urls']['home']),
            'jsonLd' => [$business],
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private static function department(array $meta, array $props, string $name): array
    {
        $department = is_array($props['department'] ?? null) ? $props['department'] : [];
        $title = (string) ($department['name'] ?? 'Department');
        $path = isset($department['id']) ? route('store.department', ['department' => $department['id']], false) : null;

        return [...$meta,
            'title' => "{$title} · {$name}",
            'description' => self::trim("Shop {$title} online at {$name}: ".((int) ($department['count'] ?? 0))." products. {$meta['description']}"),
            'image' => self::absolute($department['photoUrl'] ?? null),
            'canonical' => $path === null ? null : url($path),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private static function product(array $meta, array $props, string $name, string $mode): array
    {
        $product = is_array($props['product'] ?? null) ? $props['product'] : [];
        $title = (string) ($product['name'] ?? 'Product');
        $price = (string) ($product['price'] ?? '0');
        $url = isset($product['url']) ? url((string) $product['url']) : null;
        $image = self::absolute($product['photoUrl'] ?? null);
        $description = trim((string) ($product['description'] ?? ''));

        return [...$meta,
            'title' => "{$title} · {$name}",
            'description' => self::trim($description !== '' ? $description : "{$title} at {$name}. ".match ($mode) {
                'delivery' => 'Order online for local delivery.',
                'both' => 'Order online to collect in the shop or have it delivered.',
                default => 'Order online and collect in the shop.',
            }),
            'image' => $image,
            'canonical' => $url,
            'type' => 'product',
            'jsonLd' => [array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Product',
                'name' => $title,
                'image' => $image,
                'description' => $description === '' ? null : self::trim($description),
                'offers' => [
                    '@type' => 'Offer',
                    'price' => $price,
                    'priceCurrency' => app(Country::class)->currency(),
                    'availability' => ($product['orderable'] ?? false) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                    'url' => $url,
                    'seller' => ['@type' => 'Organization', 'name' => $name],
                ],
            ], fn ($v) => $v !== null)],
        ];
    }

    private static function absolute(mixed $url): ?string
    {
        $url = trim((string) $url);

        return $url === '' ? null : (str_starts_with($url, 'http') ? $url : url($url));
    }

    private static function trim(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > 160 ? rtrim(mb_substr($text, 0, 157)).'…' : $text;
    }
}
