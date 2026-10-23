<?php

namespace App\Http\Controllers\App;

use App\Domain\OnlineStore\Actions\SaveStoreShop;
use App\Domain\OnlineStore\Queries\CollectionShopsPage;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\OnlineStore\SaveStoreShopRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The online store's collection shops (OS-3, `store.manage`): per open shop, whether customers can collect there,
 * its collection hours per weekday, minutes to get an order ready, slot length and orders per slot. A one-shop user
 * sees and changes their own shop only.
 */
class OnlineStoreShopController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(): Response
    {
        return Inertia::render('app/online-store/shops', CollectionShopsPage::for($this->tenancy->require(), $this->tenancy->restrictedBranchId()));
    }

    public function update(SaveStoreShopRequest $request, string $branch, SaveStoreShop $save): RedirectResponse
    {
        $shop = $save->handle($this->tenancy->require(), $branch, $request->settings());
        $name = $shop->branch->name;

        return back()->with('success', $shop->enabled ? "{$name} saved. Customers can collect there." : "{$name} saved. Customers cannot collect there.");
    }
}
