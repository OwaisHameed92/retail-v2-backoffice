<?php

namespace App\Http\Controllers\App;

use App\Domain\Pricing\Actions\CancelScheduledPrice;
use App\Domain\Pricing\Actions\EndShopPrice;
use App\Domain\Pricing\Actions\SetEveryShopPrice;
use App\Domain\Pricing\Actions\SetShopPrice;
use App\Domain\Pricing\Queries\PriceChangeList;
use App\Domain\Pricing\Queries\PriceList;
use App\Domain\Pricing\Queries\ProductPrices;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\BranchPrice;
use App\Domain\TillData\Models\Product;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Pricing\EveryShopPriceRequest;
use App\Http\Requests\App\Pricing\ShopPriceRequest;
use App\Http\Requests\App\Pricing\ShopPriceRowRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shop prices on the tenant portal (module 4.3). Read: `catalogue.view`; change: `prices.manage`. A shop price is a
 * new BranchPrice row sent only to that shop; "every shop" moves the business price. A one-shop user sees and
 * changes only their shop (the requests enforce it). Another business's product or row is not found.
 */
class PriceController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('app/prices/index', PriceList::for($request));
    }

    public function changes(Request $request): Response
    {
        return Inertia::render('app/prices/changes', PriceChangeList::for($request));
    }

    public function show(string $product): Response
    {
        return Inertia::render('app/prices/show', ProductPrices::for(Product::query()->findOrFail($product)));
    }

    public function setShop(ShopPriceRequest $request, string $product, SetShopPrice $set): RedirectResponse
    {
        $shop = Branch::query()->findOrFail($request->validated('branch_id'));
        $row = $set->handle($shop, Product::query()->findOrFail($product), $request->validated('product_unit_id'),
            (string) $request->validated('price'), $request->time('valid_from'), $request->time('valid_to'));

        return back()->with('success', "£{$row->price} saved for {$shop->name}. Its tills get it at their next sync.");
    }

    public function endShop(ShopPriceRequest $request, string $product, EndShopPrice $end): RedirectResponse
    {
        $shop = Branch::query()->findOrFail($request->validated('branch_id'));
        $ended = $end->handle($shop, Product::query()->findOrFail($product), $request->validated('product_unit_id'));

        return back()->with('success', $ended === 0 ? "{$shop->name} already sells at the business price."
            : "{$shop->name} sells at the business price again from its next sync.");
    }

    public function everyShop(EveryShopPriceRequest $request, string $product, SetEveryShopPrice $set): RedirectResponse
    {
        $saved = $set->handle(Product::query()->findOrFail($product), (string) $request->validated('price'), $request->endShopIds());

        return back()->with('success', "Business price now £{$saved->product->sell_price}. Every till gets it at its next sync.");
    }

    public function cancel(ShopPriceRowRequest $request, string $row, CancelScheduledPrice $cancel): RedirectResponse
    {
        $cancel->handle(BranchPrice::query()->findOrFail($row));

        return back()->with('success', 'Scheduled price cancelled.');
    }
}
