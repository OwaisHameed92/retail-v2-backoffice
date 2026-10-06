<?php

namespace App\Http\Middleware;

use App\Domain\Billing\Support\ManualCollection;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `billing.direct-debit` (Pakistan plan P5): a Direct Debit route (GoCardless webhook, mandate setup links, the
 * owner's setup, staff mandate and subscription actions) answers 404 on an instance that collects fees by hand, as
 * if it did not exist. On a Direct Debit (GB) instance it does nothing.
 */
class EnsureDirectDebit
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(ManualCollection::active(), 404);

        return $next($request);
    }
}
