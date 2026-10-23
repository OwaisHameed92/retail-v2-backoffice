<?php

namespace App\Http\Controllers\Store;

use App\Domain\OnlineStore\Queries\StorefrontCatalogue;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StoreListingRequest;
use App\Http\Support\Store\StoreContext;
use App\Http\Support\Store\StorePages;
use App\Http\Support\Store\StorePresenter;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /{slug}/department/{department}` (OS-2): one department's products at the visitor's shop, with sort, "in
 * stock only", a price range and pages. A department with nothing shown online is the store's 404.
 */
class StoreDepartmentController extends Controller
{
    public function __invoke(StoreListingRequest $request, StoreContext $context, StorefrontCatalogue $catalogue, string $department): Response
    {
        $shop = $context->shop();
        $found = $shop === null ? null : collect($catalogue->departments($context->store, $shop))->firstWhere('id', $department);

        if ($shop === null || $found === null) {
            return StorePages::error($request, 404, $context);
        }

        $page = $catalogue->products($context->store, $shop, departmentId: $department, page: $request->page(), filters: $request->filters());

        return Inertia::render('store/department', [
            'department' => ['id' => $found['id'], 'name' => $found['name'], 'count' => $found['count']],
            'products' => StorePresenter::products($context->store, $page->items),
            'pagination' => ['page' => $page->page, 'lastPage' => $page->lastPage(), 'total' => $page->total],
            'filters' => $request->echo(),
        ])->toResponse($request);
    }
}
