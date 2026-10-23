<?php

namespace App\Http\Controllers\App;

use App\Domain\OnlineStore\Actions\SaveOnlineProductDetails;
use App\Domain\OnlineStore\Actions\SetOnlineProductsHidden;
use App\Domain\OnlineStore\Queries\OnlineProductsPage;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\OnlineStore\OnlineProductVisibilityRequest;
use App\Http\Requests\App\OnlineStore\SaveOnlineProductRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The online store's products (OS-3, `store.manage`): what the store shows at a shop and why not, and per product
 * shown or hidden, photo and short description; bulk hide / show. A one-shop user may look only (their shop).
 */
class OnlineProductController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function index(Request $request): Response
    {
        $restricted = $this->tenancy->restrictedBranchId();

        return Inertia::render('app/online-store/products', OnlineProductsPage::for($request, $this->tenancy->require(), $restricted, $restricted === null));
    }

    public function update(SaveOnlineProductRequest $request, string $product, SaveOnlineProductDetails $save): RedirectResponse
    {
        $save->handle($this->tenancy->require(), $product, $request->changes(), $request->file('photo'));

        return back()->with('success', 'Online details saved.');
    }

    public function visibility(OnlineProductVisibilityRequest $request, SetOnlineProductsHidden $set): RedirectResponse
    {
        $hidden = $request->boolean('hidden');
        $changed = $set->handle($this->tenancy->require(), $request->ids(), $hidden);
        $what = $changed === 1 ? '1 product' : "{$changed} products";

        return back()->with('success', match (true) {
            $changed === 0 => 'Nothing changed.',
            $hidden => "{$what} hidden online.",
            default => "{$what} shown online again.",
        });
    }
}
