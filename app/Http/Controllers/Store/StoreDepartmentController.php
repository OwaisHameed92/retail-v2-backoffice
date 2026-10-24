<?php

namespace App\Http\Controllers\Store;

use App\Domain\OnlineStore\Queries\StorefrontCatalogue;
use App\Domain\OnlineStore\Queries\StorePhotos;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StoreListingRequest;
use App\Http\Support\Store\StoreContext;
use App\Http\Support\Store\StorePages;
use App\Http\Support\Store\StorePresenter;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /{slug}/department/{department}` (OS-2): one aisle's products (OS-11: a department or a category, StoreAisles) at the visitor's shop, with sort, "in
 * stock only", a price range and pages, and a photo for its banner (StorePhotos, OS-8). A department with nothing
 * shown online is the store's 404.
 */
class StoreDepartmentController extends Controller
{
    public function __invoke(StoreListingRequest $request, StoreContext $context, StorefrontCatalogue $catalogue, StorePhotos $photos, string $department): Response
    {
        $shop = $context->shop();
        $found = $shop === null ? null : collect($context->departments())->firstWhere('id', $department);

        if ($shop === null || $found === null) {
            return StorePages::error($request, 404, $context);
        }

        $aisle = StoreContext::aisleFilter($found);
        $page = $catalogue->products($context->store, $shop, departmentId: $aisle['departmentId'], page: $request->page(), filters: $request->filters(),
            categoryId: $aisle['categoryId']);

        return Inertia::render('store/department', [
            'department' => ['id' => $found['id'], 'name' => $found['name'], 'count' => $found['count'],
                'photoUrl' => $found['photoUrl'] ?? $photos->departments($context->store, $shop, [$found['id']], StoreContext::aisleColumn($found))[$found['id']] ?? null],
            'products' => StorePresenter::products($context->store, $page->items, $shop),
            'pagination' => ['page' => $page->page, 'lastPage' => $page->lastPage(), 'total' => $page->total],
            'filters' => $request->echo(),
        ])->toResponse($request);
    }
}
