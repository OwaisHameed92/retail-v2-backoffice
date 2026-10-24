<?php

namespace App\Http\Controllers\Store;

use App\Domain\OnlineStore\Queries\StorefrontCatalogue;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StoreListingRequest;
use App\Http\Support\Store\StoreContext;
use App\Http\Support\Store\StorePresenter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `GET /{slug}/search?q=` (OS-2): products matching the words (name, brand, code or barcode) at the visitor's shop,
 * with the list filters. Without words: every product, so the page doubles as "all products".
 */
class StoreSearchController extends Controller
{
    public function __invoke(StoreListingRequest $request, StoreContext $context, StorefrontCatalogue $catalogue): Response
    {
        $shop = $context->shop();
        $term = $request->term();
        $page = $shop === null ? null : $catalogue->products($context->store, $shop, search: $term, page: $request->page(), filters: $request->filters());

        return Inertia::render('store/search', [
            'term' => $term,
            'products' => $page === null ? [] : StorePresenter::products($context->store, $page->items, $shop),
            'pagination' => ['page' => $page->page ?? 1, 'lastPage' => $page?->lastPage() ?? 1, 'total' => $page->total ?? 0],
            'filters' => $request->echo(),
        ]);
    }
}
