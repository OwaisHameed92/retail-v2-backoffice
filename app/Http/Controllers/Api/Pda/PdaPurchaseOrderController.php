<?php

namespace App\Http\Controllers\Api\Pda;

use App\Domain\Handheld\Actions\CancelHandheldPurchaseOrder;
use App\Domain\Handheld\Actions\SaveHandheldPurchaseOrder;
use App\Domain\Handheld\Queries\HandheldPurchaseOrders;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateHandheldToken;
use App\Http\Requests\Api\Pda\PdaCancelOrderRequest;
use App\Http\Requests\Api\Pda\PdaListRequest;
use App\Http\Requests\Api\Pda\PdaPurchaseOrderRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handheld purchase orders (docs/pda-api.md §4): the shop's orders (head-office and the till's own), and head-office
 * orders created, edited and cancelled for the user's shop while the portal still owns them. Another shop's or
 * business's order is "not found".
 */
class PdaPurchaseOrderController extends Controller
{
    public function __construct(private readonly HandheldPurchaseOrders $orders) {}

    public function index(PdaListRequest $request): JsonResponse
    {
        $page = $this->orders->page(AuthenticateHandheldToken::shop($request), $request->text('status'), $request->text('search'), $request->perPage(), $request->pageNumber());

        return response()->json(['success' => true, 'data' => $page]);
    }

    public function show(Request $request, string $order): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->orders->view($this->orders->find(AuthenticateHandheldToken::shop($request), $order))]);
    }

    public function store(PdaPurchaseOrderRequest $request, SaveHandheldPurchaseOrder $save): JsonResponse
    {
        $order = $save->handle(AuthenticateHandheldToken::shop($request), $request->order());

        return response()->json([
            'success' => true,
            'message' => $order->status?->value === 'sent'
                ? 'Order placed — the shop’s till gets it at its next sync and can book the delivery in.'
                : 'Created as a draft — the shop’s till gets it at its next sync.',
            'type' => 'purchase_order',
            'data' => $this->orders->view($order),
        ], 201);
    }

    public function update(PdaPurchaseOrderRequest $request, string $order, SaveHandheldPurchaseOrder $save): JsonResponse
    {
        $shop = AuthenticateHandheldToken::shop($request);
        $saved = $save->handle($shop, $request->order(), $this->orders->find($shop, $order));

        return response()->json(['success' => true, 'message' => 'Order updated — syncing to the shop’s till.', 'type' => 'purchase_order', 'data' => $this->orders->view($saved)]);
    }

    public function cancel(PdaCancelOrderRequest $request, string $order, CancelHandheldPurchaseOrder $cancel): JsonResponse
    {
        $saved = $cancel->handle($this->orders->find(AuthenticateHandheldToken::shop($request), $order), $request->reason());

        return response()->json(['success' => true, 'message' => 'Order cancelled — the shop’s till gets the change at its next sync.', 'type' => 'purchase_order', 'data' => $this->orders->view($saved)]);
    }
}
