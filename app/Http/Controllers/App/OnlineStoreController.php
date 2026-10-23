<?php

namespace App\Http\Controllers\App;

use App\Domain\OnlineStore\Actions\SaveStoreBuilder;
use App\Domain\OnlineStore\Actions\SetStoreLive;
use App\Domain\OnlineStore\Actions\SetTakingOrders;
use App\Domain\OnlineStore\Queries\StoreBuilderPage;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\OnlineStore\SaveStoreRequest;
use App\Http\Requests\App\OnlineStore\StoreSwitchRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The portal's store builder (OS-3, `store.manage`, 404 while the online store is off): address, brand (the look is
 * read only, set by Switch & Save), home sections, order email, go live / take offline and "Taking orders". A one-shop
 * user may look only.
 */
class OnlineStoreController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function edit(): Response
    {
        return Inertia::render('app/online-store/builder', StoreBuilderPage::for($this->tenancy->require(), $this->tenancy->restrictedBranchId() === null));
    }

    public function update(SaveStoreRequest $request, SaveStoreBuilder $save): RedirectResponse
    {
        $store = $save->handle($this->tenancy->require(), $request->settings(), $request->file('logo'), $request->file('banner'));

        return to_route('app.online-store.edit')
            ->with('success', $store->is_live ? 'Changes saved. Customers see them now.' : 'Changes saved. Go live when you are ready.');
    }

    public function live(StoreSwitchRequest $request, SetStoreLive $set): RedirectResponse
    {
        $live = $request->boolean('on');
        $set->handle($this->tenancy->require(), $live);

        return back()->with('success', $live ? 'Your store is live. Share its address with your customers.' : 'Your store is offline. Its address no longer opens.');
    }

    public function takingOrders(StoreSwitchRequest $request, SetTakingOrders $set): RedirectResponse
    {
        $on = $request->boolean('on');
        $set->handle($this->tenancy->require(), $on);

        return back()->with('success', $on ? 'Taking orders again.' : 'Orders paused. Customers can still look around the store.');
    }
}
