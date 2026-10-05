<?php

namespace App\Http\Controllers\App;

use App\Domain\Compliance\Actions\SaveProductRecall;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Compliance\ProductRecallRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Product recalls raised on the portal (module 5.7, `company.can:compliance.manage`, every shop only). `ProductRecall`
 * is hub-owned (ownership.json): each save reaches every shop's tills in their next pull. The portal raises a recall
 * and edits its text; closing, reopening, returns and the note are done at a till (ANSWERS-2026-10-06 Q3).
 */
class ProductRecallController extends Controller
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    public function store(ProductRecallRequest $request, SaveProductRecall $save): RedirectResponse
    {
        $recall = $save->handle($this->tenancy->require(), null, $request->validated());

        return to_route('app.compliance.recalls.show', $recall->id)->with('success', "Recall {$recall->reference} raised. Every till gets it at its next sync.");
    }

    public function update(ProductRecallRequest $request, string $recall, SaveProductRecall $save): RedirectResponse
    {
        $model = $save->handle($this->tenancy->require(), $recall, $request->validated());

        return back()->with('success', "Recall {$model->reference} saved.");
    }
}
