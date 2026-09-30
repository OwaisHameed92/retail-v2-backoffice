<?php

namespace App\Http\Controllers\App;

use App\Domain\Promotions\Actions\EndPromotion;
use App\Domain\Promotions\Actions\SavePromotion;
use App\Domain\Promotions\Queries\PromotionForm;
use App\Domain\Promotions\Queries\PromotionList;
use App\Domain\TillData\Models\PromotionRule;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Pricing\PromotionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Offers and standing discounts on the tenant portal (module 4.3). Read: `catalogue.view`; change:
 * `promotions.manage`. Rules are hub-owned and reach every till (each keeps its own shop's and every-shop rules).
 * A one-shop user changes only their own shop's offers (PromotionRequest). Another business's offer is not found.
 */
class PromotionController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('app/promotions/index', PromotionList::for($request));
    }

    public function create(): Response
    {
        return Inertia::render('app/promotions/form', PromotionForm::for(null));
    }

    public function store(PromotionRequest $request, SavePromotion $save): RedirectResponse
    {
        $rule = $save->handle(null, $request->promotion(), $request->items());

        return redirect()->route('app.promotions.edit', $rule->id)->with('success', "{$rule->name} saved. Tills get it at their next sync.");
    }

    public function edit(string $promotion): Response
    {
        return Inertia::render('app/promotions/form', PromotionForm::for(PromotionRule::query()->findOrFail($promotion)));
    }

    public function update(PromotionRequest $request, string $promotion, SavePromotion $save): RedirectResponse
    {
        $rule = $save->handle(PromotionRule::query()->findOrFail($promotion), $request->promotion(), $request->items());

        return back()->with('success', "{$rule->name} saved. Tills get the change at their next sync.");
    }

    public function end(PromotionRequest $request, string $promotion, EndPromotion $end): RedirectResponse
    {
        $rule = $end->handle(PromotionRule::query()->findOrFail($promotion));

        return back()->with('success', "{$rule->name} ended. Tills stop it at their next sync.");
    }
}
