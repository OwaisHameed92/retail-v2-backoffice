<?php

namespace App\Http\Controllers\App;

use App\Domain\Compliance\Actions\ChangeRecallStatus;
use App\Domain\Compliance\Actions\SaveProductRecall;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Enums\ProductRecallStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Compliance\ProductRecallRequest;
use App\Http\Requests\App\Compliance\RecallStatusRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Product recalls raised on the portal (module 5.7, `company.can:compliance.manage`, every shop only). `ProductRecall`
 * is hub-owned (ownership.json): each save reaches every shop's tills in their next pull.
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

    public function status(RecallStatusRequest $request, string $recall, ChangeRecallStatus $change): RedirectResponse
    {
        $status = ProductRecallStatus::from($request->string('status')->toString());
        $model = $change->handle($this->tenancy->require(), $recall, $status, $request->filled('returned_qty') ? $request->string('returned_qty')->toString() : null, $request->filled('note') ? $request->string('note')->toString() : null);

        return back()->with('success', $status === ProductRecallStatus::Closed ? "Recall {$model->reference} closed." : "Recall {$model->reference} reopened.");
    }
}
