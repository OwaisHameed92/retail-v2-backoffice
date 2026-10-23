<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\ChooseStoreShopRequest;
use App\Http\Support\Store\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * `POST /{slug}/shop` (OS-2): the collection shop the visitor shops at (prices and stock are that shop's). Only one
 * of this store's open shops; the basket is priced there from the next view on.
 */
class StoreShopController extends Controller
{
    public function __invoke(ChooseStoreShopRequest $request, StoreContext $context): RedirectResponse
    {
        $shop = $context->shops->firstWhere('id', (string) $request->validated('shopId'))
            ?? throw ValidationException::withMessages(['shopId' => 'Choose one of the shops listed.']);

        $context->chooseShop($shop);

        return back();
    }
}
