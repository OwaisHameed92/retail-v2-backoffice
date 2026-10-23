<?php

namespace App\Http\Controllers\Store;

use App\Domain\OnlineStore\Actions\AddToBasket;
use App\Domain\OnlineStore\Actions\UpdateBasketLine;
use App\Domain\OnlineStore\Basket\BasketRules;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\BasketLineRequest;
use App\Http\Support\Store\StoreContext;
use App\Http\Support\Store\StorePresenter;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public store's basket (OS-2), kept in the session per store: `GET basket` (priced again from the catalogue
 * on every view; lines no longer orderable are removed with a notice), `POST basket` add, `PATCH basket/{product}`
 * quantity (0 removes), `DELETE basket/{product}` remove.
 */
class StoreBasketController extends Controller
{
    public function show(StoreContext $context): Response
    {
        $priced = $context->priced();

        return Inertia::render('store/basket', [
            'lines' => StorePresenter::basketLines($context->store, $priced),
        ]);
    }

    public function add(BasketLineRequest $request, StoreContext $context, AddToBasket $add): RedirectResponse
    {
        $basket = $add->handle($context->store, $context->shop(), $context->basket(), (string) $request->validated('product'), (string) $request->validated('quantity'));
        $context->save($basket);
        $line = $basket->lines[(string) $request->validated('product')];

        return back()->with('success', 'Added '.$line['name'].' to your basket ('.BasketRules::display($line['qty']).' in it now).');
    }

    public function update(BasketLineRequest $request, StoreContext $context, UpdateBasketLine $update, string $product): RedirectResponse
    {
        $context->save($update->handle($context->store, $context->shop(), $context->basket(), $product, (string) $request->validated('quantity')));

        return back();
    }

    public function remove(StoreContext $context, UpdateBasketLine $update, string $product): RedirectResponse
    {
        $context->save($update->handle($context->store, $context->shop(), $context->basket(), $product, '0'));

        return back();
    }
}
