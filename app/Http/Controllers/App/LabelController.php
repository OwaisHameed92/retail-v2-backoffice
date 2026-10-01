<?php

namespace App\Http\Controllers\App;

use App\Domain\Labels\Actions\AddLabelsToQueue;
use App\Domain\Labels\Actions\DeleteLabelTemplate;
use App\Domain\Labels\Actions\PrintLabels;
use App\Domain\Labels\Actions\SaveLabelTemplate;
use App\Domain\Labels\Actions\UpdateLabelQueue;
use App\Domain\Labels\Queries\LabelProductSearch;
use App\Domain\Labels\Queries\LabelQueuePage;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Labels\LabelShopRequest;
use App\Http\Requests\App\Labels\LabelTemplateRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shelf-edge labels on the tenant portal (gap #6, `labels.print`): a shop's label queue (filled by price and offer
 * changes, or by hand), preview, PDF on A4 sheets or a label-printer roll, and templates. Portal-only: the contract has
 * no label queue entity. A one-shop user works only on their shop (the requests enforce it).
 */
class LabelController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('app/labels/index', LabelQueuePage::for($request));
    }

    public function products(Request $request): JsonResponse
    {
        $restricted = app(CurrentCompany::class)->restrictedBranchId();
        $shopId = (string) $request->query('branch_id');
        abort_if($restricted !== null && $shopId !== $restricted, 403);

        return response()->json(['products' => LabelProductSearch::for(Branch::query()->findOrFail($shopId), (string) $request->query('q'))]);
    }

    public function queue(LabelShopRequest $request, AddLabelsToQueue $add): RedirectResponse
    {
        $count = $add->handle($request->shop(), Arr::only($request->validated(), ['product_ids', 'department_id', 'supplier_id']));

        return back()->with('success', $count === 1 ? '1 label added to the queue.' : "{$count} labels added to the queue.");
    }

    public function printed(LabelShopRequest $request, UpdateLabelQueue $update): RedirectResponse
    {
        $count = $update->handle($request->shop(), $request->ids(), 'printed');

        return back()->with('success', $count === 1 ? '1 label marked as printed.' : "{$count} labels marked as printed.");
    }

    public function remove(LabelShopRequest $request, UpdateLabelQueue $update): RedirectResponse
    {
        $count = $update->handle($request->shop(), $request->ids(), 'remove');

        return back()->with('success', $count === 1 ? '1 label taken off the queue.' : "{$count} labels taken off the queue.");
    }

    public function copies(LabelShopRequest $request, UpdateLabelQueue $update): RedirectResponse
    {
        $update->handle($request->shop(), $request->ids(), 'copies', (int) $request->validated('copies', 1));

        return back()->with('success', 'Copies saved.');
    }

    public function preview(LabelShopRequest $request, PrintLabels $print): JsonResponse
    {
        return response()->json($print->preview($request->shop(), $request->ids(), $request->validated('template_id'), (int) $request->validated('skip', 0)));
    }

    public function pdf(LabelShopRequest $request, PrintLabels $print): HttpResponse
    {
        $shop = $request->shop();
        $pdf = $print->handle($shop, $request->ids(), $request->validated('template_id'), (int) $request->validated('skip', 0), $request->boolean('mark_printed'));
        $name = 'shelf-labels-'.str($shop->name)->slug().'-'.CarbonImmutable::now('Europe/London')->format('Y-m-d-Hi').'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function storeTemplate(LabelTemplateRequest $request, SaveLabelTemplate $save): RedirectResponse
    {
        $template = $save->handle(null, $request->validated());

        return back()->with('success', "Template \"{$template->name}\" saved.");
    }

    public function updateTemplate(LabelTemplateRequest $request, SaveLabelTemplate $save): RedirectResponse
    {
        $template = $save->handle($request->template(), $request->validated());

        return back()->with('success', "Template \"{$template->name}\" saved.");
    }

    public function destroyTemplate(LabelTemplateRequest $request, DeleteLabelTemplate $delete): RedirectResponse
    {
        $template = $request->template();
        abort_if($template === null, 404);
        $delete->handle($template);

        return back()->with('success', "Template \"{$template->name}\" deleted.");
    }
}
