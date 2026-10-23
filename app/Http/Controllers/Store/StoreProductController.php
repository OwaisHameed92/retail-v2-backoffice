<?php

namespace App\Http\Controllers\Store;

use App\Domain\OnlineStore\Basket\BasketRules;
use App\Domain\OnlineStore\Data\CatalogueFilters;
use App\Domain\OnlineStore\Data\CatalogueItem;
use App\Domain\OnlineStore\Queries\CollectionDays;
use App\Domain\OnlineStore\Queries\StorefrontCatalogue;
use App\Http\Controllers\Controller;
use App\Http\Support\Store\StoreContext;
use App\Http\Support\Store\StorePages;
use App\Http\Support\Store\StorePresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /{slug}/product/{product}` (OS-2): one product at the visitor's shop with its quantity picker, the next
 * collection time and a few more from its department. Not shown online (another business's product included) →
 * the store's 404.
 */
class StoreProductController extends Controller
{
    public const RELATED = 4;

    public function __invoke(Request $request, StoreContext $context, StorefrontCatalogue $catalogue, CollectionDays $days, string $product): Response
    {
        $store = $context->store;
        $shop = $context->shop();
        $item = $shop === null ? null : $catalogue->product($store, $shop, $product);

        if ($shop === null || $item === null) {
            return StorePages::error($request, 404, $context);
        }

        $department = $item->departmentId === null ? null : collect($catalogue->departments($store, $shop))->firstWhere('id', $item->departmentId);
        $related = $item->departmentId === null ? [] : array_values(array_filter(
            $catalogue->products($store, $shop, departmentId: $item->departmentId, perPage: self::RELATED + 1, filters: new CatalogueFilters(inStockOnly: true))->items,
            fn (CatalogueItem $other) => $other->id !== $item->id,
        ));
        $next = $context->takingOrders() && $item->orderable ? $days->next($shop) : null;

        return Inertia::render('store/product', [
            'product' => StorePresenter::products($store, [$item])[0],
            'department' => $department === null ? null : [
                'name' => $department['name'], 'url' => route('store.department', ['department' => $department['id']], false),
            ],
            'inBasket' => BasketRules::display($context->basket()->quantity($item->id)),
            'nextCollection' => $next === null ? null : CollectionDays::describe($next->from, $next->to),
            'related' => StorePresenter::products($store, array_slice($related, 0, self::RELATED)),
        ])->toResponse($request);
    }
}
