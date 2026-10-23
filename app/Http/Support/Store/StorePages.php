<?php

namespace App\Http\Support\Store;

use App\Domain\OnlineStore\Queries\StorefrontCatalogue;
use App\Domain\OnlineStore\Support\StoreScope;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public store's page frame (OS-2): its own root view (`resources/views/store.blade.php`, the shop's brand, not
 * the portal's), the props every store page shares (store, departments, basket summary), and its own error pages
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
        $departments = $shop === null ? [] : app(StorefrontCatalogue::class)->departments($store, $shop);

        return [
            'name' => $name,
            'initials' => StorePresenter::initials($name),
            'logoUrl' => StorePresenter::publicUrl($store->logo_path),
            'brand' => StorePresenter::brand($store),
            'takingOrders' => $context->takingOrders(),
            'shop' => $shop === null ? null : ['id' => $shop->id, 'name' => $shop->branch->name],
            'shopCount' => $context->shops->count(),
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
            ],
        ];
    }

    public static function error(Request $request, int $status, ?StoreContext $context = null): Response
    {
        [$title, $message] = self::ERRORS[$status] ?? self::ERRORS[404];

        if ($context === null) {
            $title = $status === 404 ? 'This shop isn\'t online' : $title;
            $message = $status === 404 ? 'Check the address, or contact the shop. Their online store may be closed for now.' : $message;
        } else {
            self::share($context);
        }

        Inertia::setRootView(self::ROOT_VIEW);

        return Inertia::render('store/error', ['status' => $status, 'title' => $title, 'message' => $message])
            ->toResponse($request)
            ->setStatusCode($status);
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
