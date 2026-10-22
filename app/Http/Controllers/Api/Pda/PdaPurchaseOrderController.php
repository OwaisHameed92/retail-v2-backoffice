<?php

namespace App\Http\Controllers\Api\Pda;

use App\Domain\Handheld\Actions\CancelHandheldPurchaseOrder;
use App\Domain\Handheld\Actions\CreateHandheldPurchaseOrder;
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
 * orders created, edited and cancelled for the user's shop while the portal still owns them. A create repeating a
 * `client_reference` answers 200 with the order already made. Another shop's or business's order is "not found".
 */
class PdaPurchaseOrderController extends Controller
{
    public function __construct(private readonly HandheldPurchaseOrders $orders) {}

    public function index(PdaListRequest $request): JsonResponse
    {
        $page = $this->orders->page(AuthenticateHandheldToken::shop($request), $request->text('status'), $request->text('search'), $request->perPage(), $request->pageNumber(),
            $request->text('client_reference'));

        return response()->json(['success' => true, 'data' => $page]);
    }

    public function show(Request $request, string $order): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->orders->view($this->orders->find(AuthenticateHandheldToken::shop($request), $order))]);
    }

    public function store(PdaPurchaseOrderRequest $request, CreateHandheldPurchaseOrder $create): JsonResponse
    {
        [$order, $created] = $create->handle(AuthenticateHandheldToken::user($request), AuthenticateHandheldToken::shop($request), $request->order(), $request->clientReference());

        return response()->json([
            'success' => true,
            'message' => match (true) {
                ! $created => 'Already saved — this is the order made with this client_reference.',
                $order->status?->value === 'sent' => 'Order placed — the shop’s till gets it at its next sync and can book the delivery in.',
                default => 'Created as a draft — the shop’s till gets it at its next sync.',
            },
            'type' => 'purchase_order',
            'data' => $this->orders->view($order),
        ], $created ? 201 : 200);
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
