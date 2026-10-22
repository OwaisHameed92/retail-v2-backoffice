<?php

namespace App\Http\Controllers\Api\Pda;

use App\Domain\Handheld\Actions\ReceiveHandheldDelivery;
use App\Domain\Handheld\Queries\HandheldPurchaseOrders;
use App\Domain\Handheld\Queries\HandheldReceipts;
use App\Domain\Handheld\Support\StockDraftView;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateHandheldToken;
use App\Http\Requests\Api\Pda\PdaReceiveRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Goods-in on the handheld (PDA_PHASE2_REQUEST §3; docs/pda-api.md "Receiving"): a delivery against an order of the
 * user's shop (drafted as the till's goods-in, posted at its next sync), the order's deliveries, and what can be
 * returned per line. Behind RequireStockDrafts (501 while `PDA_STOCK_DRAFTS` is off). Another shop's order: 404.
 */
class PdaReceivingController extends Controller
{
    public function __construct(private readonly HandheldPurchaseOrders $orders, private readonly HandheldReceipts $receipts) {}

    public function receive(PdaReceiveRequest $request, string $order, ReceiveHandheldDelivery $receive): JsonResponse
    {
        $shop = AuthenticateHandheldToken::shop($request);
        $model = $this->orders->find($shop, $order);
        [$receipt, $created] = $receive->handle(AuthenticateHandheldToken::user($request), $shop, $model, $request->body(), $request->clientReference());

        return response()->json([
            'success' => true,
            'message' => $created ? 'Delivery saved — the shop’s till books it in at its next sync.' : 'Already saved — this is the delivery made with this client_reference.',
            'type' => 'purchase_order',
            'data' => $this->orders->view($this->orders->find($shop, $order)),
            'receipt' => StockDraftView::receipt($receipt),
        ], $created ? 201 : 200);
    }

    public function receipts(Request $request, string $order): JsonResponse
    {
        $shop = AuthenticateHandheldToken::shop($request);

        return response()->json(['success' => true, 'data' => $this->receipts->receipts($shop, $this->orders->find($shop, $order))]);
    }

    public function returnable(Request $request, string $order): JsonResponse
    {
        $shop = AuthenticateHandheldToken::shop($request);

        return response()->json(['success' => true, 'data' => $this->receipts->returnable($shop, $this->orders->find($shop, $order))]);
    }
}
