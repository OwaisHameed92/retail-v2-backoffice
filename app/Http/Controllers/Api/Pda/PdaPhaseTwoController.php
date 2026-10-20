<?php

namespace App\Http\Controllers\Api\Pda;

use App\Domain\Handheld\Support\PdaErrors;
use App\Http\Controllers\Controller;

/**
 * Legacy PDA calls whose work is till-owned in contract v1.4.1 (receiving goods, receipts, returnable quantities,
 * purchase returns, stock adjustments): 501 NOT_AVAILABLE_YET until phase 2 (docs/pda-api.md "Phase 2").
 */
class PdaPhaseTwoController extends Controller
{
    public function __invoke(): never
    {
        throw PdaErrors::notAvailableYet();
    }
}
