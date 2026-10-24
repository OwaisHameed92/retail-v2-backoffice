<?php

namespace App\Http\Controllers\App;

use App\Domain\OnlineStore\Actions\DeleteStoreBanner;
use App\Domain\OnlineStore\Actions\OrderStoreBanners;
use App\Domain\OnlineStore\Actions\SaveStoreBanner;
use App\Domain\OnlineStore\Queries\StoreProductSearch;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\OnlineStore\SaveStoreBannerRequest;
use App\Http\Requests\App\Setup\CompanyWideWriteRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The store builder's banners and product picker (OS-8, `store.manage`, 404 while the online store is off): add,
 * change, delete and order the home banners (a one-shop user may not: 403), and search the products the store shows
 * for a banner's button or the hand-picked featured products (JSON).
 */
class OnlineStoreBannerController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function store(SaveStoreBannerRequest $request, SaveStoreBanner $save): RedirectResponse
    {
        $save->handle($this->tenancy->require(), null, $request->fields(), $request->file('photo'));

        return back()->with('success', 'Banner added. Customers see it on the home page now.');
    }

    public function update(SaveStoreBannerRequest $request, string $banner, SaveStoreBanner $save): RedirectResponse
    {
        $saved = $save->handle($this->tenancy->require(), $banner, $request->fields(), $request->file('photo'));

        return back()->with('success', $saved->is_on ? 'Banner saved.' : 'Banner saved. It is off, so customers do not see it.');
    }

    public function destroy(CompanyWideWriteRequest $request, string $banner, DeleteStoreBanner $delete): RedirectResponse
    {
        $delete->handle($this->tenancy->require(), $banner);

        return back()->with('success', 'Banner deleted.');
    }

    public function order(CompanyWideWriteRequest $request, OrderStoreBanners $order): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'max:20'], 'ids.*' => ['string', 'max:26']])['ids'];
        $order->handle($this->tenancy->require(), array_values(array_map('strval', $ids)));

        return back()->with('success', 'Banner order saved.');
    }

    public function products(Request $request, StoreProductSearch $search): JsonResponse
    {
        $term = mb_substr(trim((string) $request->query('q', '')), 0, 80);

        return response()->json(['products' => $search->handle($this->tenancy->require(), $term)]);
    }
}
