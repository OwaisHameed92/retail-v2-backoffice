<?php

namespace App\Http\Controllers\Api\Pda;

use App\Domain\Handheld\Actions\CancelHandheldStockDraft;
use App\Domain\Handheld\Actions\SaveHandheldPurchaseReturn;
use App\Domain\StockDrafts\Enums\StockDraftKind;
use App\Http\Middleware\AuthenticateHandheldToken;
use App\Http\Requests\Api\Pda\PdaCancelOrderRequest;
use App\Http\Requests\Api\Pda\PdaListRequest;
use App\Http\Requests\Api\Pda\PdaPurchaseReturnRequest;
use Illuminate\Http\JsonResponse;

/**
 * Handheld purchase returns (PDA_PHASE2_REQUEST §4; docs/pda-api.md "Purchase returns"): returns to a supplier from
 * the user's shop, drafted as the till's own return; the till books it at its next sync. The list shows the returns
 * made from the portal or the handheld. Another shop's or business's return is "not found".
 */
class PdaPurchaseReturnController extends PdaStockController
{
    private const TYPE = 'purchase_return';

    public function index(PdaListRequest $request): JsonResponse
    {
        return $this->list($this->drafts->page(AuthenticateHandheldToken::shop($request), StockDraftKind::PurchaseReturn, [
            'client_reference' => $request->text('client_reference'), 'purchase_order_id' => $request->text('purchase_order_id'),
        ], $request->perPage(), $request->pageNumber()));
    }

    public function store(PdaPurchaseReturnRequest $request, SaveHandheldPurchaseReturn $save): JsonResponse
    {
        [$draft, $created] = $save->create(AuthenticateHandheldToken::user($request), AuthenticateHandheldToken::shop($request), $request->body(), $request->clientReference());

        return $created
            ? $this->reply($draft, self::TYPE, 'Saved — the shop’s till books the return at its next sync.', 201)
            : $this->reply($draft, self::TYPE, 'Already saved — this is the return made with this client_reference.');
    }

    public function update(PdaPurchaseReturnRequest $request, string $return, SaveHandheldPurchaseReturn $save): JsonResponse
    {
        $shop = AuthenticateHandheldToken::shop($request);
        $draft = $save->update(AuthenticateHandheldToken::user($request), $shop, $this->drafts->find($shop, StockDraftKind::PurchaseReturn, $return), $request->body());

        return $this->reply($draft, self::TYPE, 'Return updated — the shop’s till books it at its next sync.');
    }

    public function cancel(PdaCancelOrderRequest $request, string $return, CancelHandheldStockDraft $cancel): JsonResponse
    {
        $draft = $this->drafts->find(AuthenticateHandheldToken::shop($request), StockDraftKind::PurchaseReturn, $return);

        return $this->reply($cancel->handle(AuthenticateHandheldToken::user($request), $draft, $request->reason()), self::TYPE, 'Return cancelled — the till never got it.');
    }
}
