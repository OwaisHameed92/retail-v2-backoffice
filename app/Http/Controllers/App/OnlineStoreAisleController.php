<?php

namespace App\Http\Controllers\App;

use App\Domain\OnlineStore\Actions\SaveStoreAisles;
use App\Domain\OnlineStore\Support\StoreImages;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Setup\CompanyWideWriteRequest;
use Illuminate\Http\RedirectResponse;

/**
 * The store builder's "Aisles" (OS-11, `store.manage`, store-wide: a one-shop user gets 403): departments or
 * categories, and per aisle its order, shown or hidden, display name and photo (SaveStoreAisles).
 */
class OnlineStoreAisleController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function update(CompanyWideWriteRequest $request, SaveStoreAisles $save): RedirectResponse
    {
        $save->handle($this->tenancy->require(), ['source' => $request->input('source'), 'aisles' => $request->input('aisles', [])]);

        return back()->with('success', 'Aisles saved. Customers see them now.');
    }

    public function photo(CompanyWideWriteRequest $request, string $aisle, SaveStoreAisles $save): RedirectResponse
    {
        $request->validate(['photo' => ['required', ...StoreImages::rules()]], StoreImages::messages('photo'));
        $save->photo($this->tenancy->require(), $aisle, $request->file('photo'));

        return back()->with('success', 'Aisle photo saved.');
    }

    public function removePhoto(CompanyWideWriteRequest $request, string $aisle, SaveStoreAisles $save): RedirectResponse
    {
        $save->photo($this->tenancy->require(), $aisle, null);

        return back()->with('success', 'Aisle photo removed. A product photo from the aisle is used instead.');
    }
}
