<?php

namespace App\Http\Middleware;

use App\Domain\Handheld\Support\PdaErrors;
use App\Domain\StockDrafts\Support\StockDraftSwitch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The handheld stock calls (stock adjustments, stock takes, receiving, receipts, returnable, purchase returns) while
 * `PDA_STOCK_DRAFTS` is off: 501 NOT_AVAILABLE_YET, before any body is read — exactly as before contract proposal
 * pda-stock-drafts. Runs after the token check (an unknown token is still 401) and the rate limit. Also 501 for a shop
 * whose till is older than PDA_STOCK_DRAFTS_MIN_TILL (StockDraftSwitch::servesShop).
 */
class RequireStockDrafts
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! StockDraftSwitch::servesShop(AuthenticateHandheldToken::shop($request))) {
            throw PdaErrors::notAvailableYet();
        }

        return $next($request);
    }
}
