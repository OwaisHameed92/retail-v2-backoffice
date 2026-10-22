<?php

namespace App\Http\Controllers\Api\Pda;

use App\Domain\Handheld\Actions\CancelHandheldStockDraft;
use App\Domain\Handheld\Actions\SaveHandheldStockAdjustment;
use App\Domain\StockDrafts\Enums\StockDraftKind;
use App\Http\Middleware\AuthenticateHandheldToken;
use App\Http\Requests\Api\Pda\PdaCancelOrderRequest;
use App\Http\Requests\Api\Pda\PdaListRequest;
use App\Http\Requests\Api\Pda\PdaStockAdjustmentRequest;
use Illuminate\Http\JsonResponse;

/**
 * Handheld stock adjustments (PDA_PHASE2_REQUEST §1; docs/pda-api.md "Stock adjustments"): `StockAdjustmentDraft`s of
 * the user's shop that its till applies at its next sync. Another shop's or business's adjustment is "not found".
 */
class PdaStockAdjustmentController extends PdaStockController
{
    private const TYPE = 'stock_adjustment';

    public function index(PdaListRequest $request): JsonResponse
    {
        return $this->list($this->drafts->page(AuthenticateHandheldToken::shop($request), StockDraftKind::Adjustment, [
            'client_reference' => $request->text('client_reference'), 'product_id' => $request->text('product_id'),
        ], $request->perPage(), $request->pageNumber()));
    }

    public function store(PdaStockAdjustmentRequest $request, SaveHandheldStockAdjustment $save): JsonResponse
    {
        [$draft, $created] = $save->create(AuthenticateHandheldToken::user($request), AuthenticateHandheldToken::shop($request), $request->body(), $request->clientReference());

        return $created
            ? $this->reply($draft, self::TYPE, 'Saved — the shop’s till applies it at its next sync.', 201)
            : $this->reply($draft, self::TYPE, 'Already saved — this is the adjustment made with this client_reference.');
    }

    public function update(PdaStockAdjustmentRequest $request, string $adjustment, SaveHandheldStockAdjustment $save): JsonResponse
    {
        $shop = AuthenticateHandheldToken::shop($request);
        $draft = $save->update(AuthenticateHandheldToken::user($request), $shop, $this->drafts->find($shop, StockDraftKind::Adjustment, $adjustment), $request->body());

        return $this->reply($draft, self::TYPE, 'Adjustment updated — the shop’s till applies it at its next sync.');
    }

    public function cancel(PdaCancelOrderRequest $request, string $adjustment, CancelHandheldStockDraft $cancel): JsonResponse
    {
        $draft = $this->drafts->find(AuthenticateHandheldToken::shop($request), StockDraftKind::Adjustment, $adjustment);

        return $this->reply($cancel->handle(AuthenticateHandheldToken::user($request), $draft, $request->reason()), self::TYPE, 'Adjustment cancelled — the till never got it.');
    }
}
