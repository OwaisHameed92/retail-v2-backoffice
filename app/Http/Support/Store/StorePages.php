<?php

namespace App\Http\Support\Store;

use App\Domain\OnlineStore\Models\OnlineStoreShop;
use App\Domain\OnlineStore\Support\BrandPalette;
use App\Domain\OnlineStore\Support\ShopMethods;
use App\Domain\OnlineStore\Support\ShopOpenNow;
use App\Domain\OnlineStore\Support\StoreScope;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public store's page frame (OS-2): its own root view (`resources/views/store.blade.php`, the shop's brand, not
 * the portal's), the props every store page shares (store with its template, its colours from the brand colour
 * (BrandPalette, OS-8), departments, basket summary; the React pages switch fonts, colours and home sections by
 * `store.template`, OS-5), and its own error pages
 * (404 unknown or closed store and missing pages, 403 bad order links, 429 too many tries).
 */
final class StorePages
{
    public const ROOT_VIEW = 'store';

    private const ERRORS = [
        403 => ['This link has expired', 'Open the order from the link in your email, or ask the shop for help.'],
        404 => ['We can\'t find that page', 'It may have moved, or the product is no longer sold online.'],
        429 => ['Slow down a moment', 'That was a lot of tries in a short time. Wait a minute, then try again.'],
    ];

    /** Shares the store frame with every page of this request. */
    public static function share(StoreContext $context): void
    {
        Inertia::setRootView(self::ROOT_VIEW);
        Inertia::share([
            'store' => fn () => self::store($context),
            'basket' => fn () => StorePresenter::basketSummary($context->priced()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function store(StoreContext $context): array
    {
        $store = $context->store;
        $name = StoreScope::company($store)->name;
        $shop = $context->shop();
        $departments = $context->departments();

        return [
            'name' => $name,
            'initials' => StorePresenter::initials($name),
            'logoUrl' => StorePresenter::publicUrl($store->logo_path),
            'brand' => StorePresenter::brand($store),
            'palette' => BrandPalette::for(StorePresenter::brand($store), $store->template),
            'template' => $store->template->value,
            'takingOrders' => $context->takingOrders(),
            // OS-12: collection, delivery or both (ShopMethods), and the words that follow from it (StoreWording).
            'methods' => ShopMethods::forStore($store, $context->shops),
            'wording' => StoreWording::for($context),
            'shop' => $shop === null ? null : self::shop($shop),
            'shopCount' => $context->shops->count(),
            // Audit OSA-34: the shops to choose from in the header (only when there is a choice).
            'shops' => $context->shops->count() > 1 ? $context->shops->map(fn (OnlineStoreShop $s) => [
                'id' => $s->id, 'name' => StorePresenter::shop($s)['name'],
            ])->values()->all() : [],
            'departments' => array_map(fn (array $d) => [
                'id' => $d['id'], 'name' => $d['name'], 'count' => $d['count'],
                'url' => route('store.department', ['department' => $d['id']], false),
            ], $departments),
            'urls' => [
                'home' => route('store.home', [], false),
                'search' => route('store.search', [], false),
                'basket' => route('store.basket', [], false),
                'basketAdd' => route('store.basket.add', [], false),
                'checkout' => route('store.checkout', [], false),
                'shop' => route('store.shop', [], false),
                ...StoreLegal::urls(),
            ],
            // OS-11 (audit OSA-2): who sells (footer, legal pages, checkout).
            'trader' => StoreLegal::trader($context)->toArray(),
        ];
    }

    /**
     * The visitor's shop for the frame (name, and the address, phone, hours and "open now" the header and footer
     * show). OS-12: a delivery-only shop's hours are its delivery hours.
     *
     * @return array<string, mixed>
     */
    private static function shop(OnlineStoreShop $shop): array
    {
        $shop->loadMissing('branch');
        $info = StorePresenter::shop($shop);
        $hours = ! $info['collects'] && is_array($info['delivery']) ? $info['delivery']['hours'] : $info['hours'];

        return ['id' => $shop->id, 'name' => $info['name'], 'address' => $info['address'], 'phone' => $info['phone'],
            'hours' => $hours, 'openNow' => ShopOpenNow::describe($shop)];
    }

    /**
     * @param  array{0: string, 1: string}|null  $text  OS-9: the page's own title and message instead of the status's
     */
    public static function error(Request $request, int $status, ?StoreContext $context = null, ?array $text = null): Response
    {
        [$title, $message] = $text ?? self::ERRORS[$status] ?? self::ERRORS[404];

        if ($context === null) {
            $title = $status === 404 ? 'This shop isn\'t online' : $title;
            $message = $status === 404 ? 'Check the address, or contact the shop. Their online store may be closed for now.' : $message;
        } else {
            self::share($context);
        }

        Inertia::setRootView(self::ROOT_VIEW);

        $response = Inertia::render('store/error', ['status' => $status, 'title' => $title, 'message' => $message])
            ->toResponse($request)
            ->setStatusCode($status);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /**
     * The 429 page for the store's named limiters (StoreRateLimits).
     *
     * @param  array<string, string>  $headers
     */
    public static function tooMany(Request $request, array $headers): Response
    {
        $context = $request->attributes->get(StoreContext::class);
        $response = self::error($request, 429, $context instanceof StoreContext ? $context : null);
        $response->headers->add($headers);

        return $response;
    }
}
