<?php

namespace App\Http\Controllers\Api\Pda;

use App\Domain\Handheld\Actions\CancelHandheldStockDraft;
use App\Domain\Handheld\Actions\SaveHandheldStockTake;
use App\Domain\Handheld\Support\StockDraftView;
use App\Domain\StockDrafts\Enums\StockDraftKind;
use App\Http\Middleware\AuthenticateHandheldToken;
use App\Http\Requests\Api\Pda\PdaCancelOrderRequest;
use App\Http\Requests\Api\Pda\PdaListRequest;
use App\Http\Requests\Api\Pda\PdaStockTakeRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handheld stock takes (PDA_PHASE2_REQUEST §1a; docs/pda-api.md "Stock takes"): shelf counts of the user's shop,
 * drafted as the till's own stock take; the till books the variance per line at its next sync and the lines then
 * carry `expected_quantity` and `variance`. Another shop's or business's stock take is "not found".
 */
class PdaStockTakeController extends PdaStockController
{
    private const TYPE = 'stock_take';

    public function index(PdaListRequest $request): JsonResponse
    {
        return $this->list($this->drafts->page(AuthenticateHandheldToken::shop($request), StockDraftKind::StockTake, [
            'client_reference' => $request->text('client_reference'),
        ], $request->perPage(), $request->pageNumber()));
    }

    public function show(Request $request, string $take): JsonResponse
    {
        return response()->json(['success' => true, 'data' => StockDraftView::of($this->drafts->find(AuthenticateHandheldToken::shop($request), StockDraftKind::StockTake, $take))]);
    }

    public function store(PdaStockTakeRequest $request, SaveHandheldStockTake $save): JsonResponse
    {
        [$draft, $created] = $save->create(AuthenticateHandheldToken::user($request), AuthenticateHandheldToken::shop($request), $request->body(), $request->clientReference());

        return $created
            ? $this->reply($draft, self::TYPE, 'Saved — the shop’s till books the differences at its next sync.', 201)
            : $this->reply($draft, self::TYPE, 'Already saved — this is the stock take made with this client_reference.');
    }

    public function update(PdaStockTakeRequest $request, string $take, SaveHandheldStockTake $save): JsonResponse
    {
        $draft = $this->drafts->find(AuthenticateHandheldToken::shop($request), StockDraftKind::StockTake, $take);

        return $this->reply($save->update(AuthenticateHandheldToken::user($request), $draft, $request->body()), self::TYPE,
            'Stock take updated — the shop’s till books the differences at its next sync.');
    }

    public function cancel(PdaCancelOrderRequest $request, string $take, CancelHandheldStockDraft $cancel): JsonResponse
    {
        $draft = $this->drafts->find(AuthenticateHandheldToken::shop($request), StockDraftKind::StockTake, $take);

        return $this->reply($cancel->handle(AuthenticateHandheldToken::user($request), $draft, $request->reason()), self::TYPE, 'Stock take cancelled — the till never got it.');
    }
}
