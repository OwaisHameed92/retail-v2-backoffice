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
 * whose till is older than PDA_STOCK_DRAFTS_MIN_TILL (StockDraftSwitch::servesShop), and 403 FEATURE_NOT_INCLUDED for
 * a shop whose till licence leaves out `purchasing` (checked first while the switch is on: "Not in licence" in the app).
 */
class RequireStockDrafts
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! StockDraftSwitch::enabled()) {
            throw PdaErrors::notAvailableYet();
        }

        $shop = AuthenticateHandheldToken::shop($request);

        if (! StockDraftSwitch::includedForShop($shop)) {
            throw PdaErrors::featureNotIncluded();
        }

        if (! StockDraftSwitch::servesShop($shop)) {
            throw PdaErrors::notAvailableYet();
        }

        return $next($request);
    }
}
