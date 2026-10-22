<?php

namespace App\Http\Controllers\Api\Pda;

use App\Domain\Handheld\Queries\HandheldStockDrafts;
use App\Domain\Handheld\Support\StockDraftView;
use App\Domain\StockDrafts\Models\StockDraft;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Shared by the handheld stock controllers (stock adjustments, stock takes, purchase returns; contract proposal
 * pda-stock-drafts). Every route behind them also has RequireStockDrafts: 501 while `PDA_STOCK_DRAFTS` is off.
 * Writes answer `{success, message, type, data}`: 201 when made, 200 for a repeated `client_reference` or a change.
 */
abstract class PdaStockController extends Controller
{
    public function __construct(protected readonly HandheldStockDrafts $drafts) {}

    protected function reply(StockDraft $draft, string $type, string $message, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'type' => $type, 'data' => StockDraftView::of($draft)], $status);
    }

    /**
     * @param  array<string, mixed>  $page
     */
    protected function list(array $page): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $page]);
    }
}
