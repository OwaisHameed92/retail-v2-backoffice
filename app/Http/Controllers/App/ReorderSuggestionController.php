<?php

namespace App\Http\Controllers\App;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Ai\Exceptions\AiAccessDenied;
use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Domain\Purchasing\Actions\CreateReorderOrders;
use App\Domain\Purchasing\Actions\SummariseReorderSuggestions;
use App\Domain\Purchasing\Reorder\ReorderFilters;
use App\Domain\Purchasing\Reorder\ReorderPage;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\PurchaseOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Purchasing\ReorderOrdersRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reorder suggestions (module 6.4): `company.can:purchasing.view` to see them (a one-shop user: their shop), draft
 * orders from them with `purchasing.manage` and every shop, and an optional AI note (`ai.use`).
 */
class ReorderSuggestionController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('app/purchasing/suggestions', ReorderPage::for(ReorderFilters::from($request), $request->user()));
    }

    public function store(ReorderOrdersRequest $request, CreateReorderOrders $create): RedirectResponse
    {
        $orders = $create->handle($request->lines(), $request->validated('notes'));
        $references = implode(', ', array_map(fn (PurchaseOrder $o) => (string) $o->reference, $orders));
        $count = count($orders);

        return redirect()->route('app.purchasing.index', ['kind' => 'orders', 'origin' => 'headOffice', 'status' => 'draft'])
            ->with('success', ($count === 1 ? 'Drafted 1 order' : "Drafted {$count} orders").": {$references}. Check and send each one to its supplier.");
    }

    public function note(Request $request, CurrentCompany $current, SummariseReorderSuggestions $summarise): JsonResponse
    {
        try {
            $context = AiContext::forUser($request->user(), $current->require(), AiFeature::ReorderSuggestions);

            return response()->json(['note' => $summarise->handle($context, ReorderFilters::from($request))]);
        } catch (AiAccessDenied) {
            abort(403);
        } catch (AiUnavailable $e) {
            return response()->json(['note' => null, 'message' => $e->getMessage()]);
        }
    }
}
