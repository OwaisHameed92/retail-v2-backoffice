<?php

namespace App\Http\Middleware;

use App\Domain\Salon\Support\SalonOnline;
use App\Domain\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Salon W3b: the tenant salon routes answer 404 unless SALON_ONLINE is on and the current business has a salon shop
 * (SalonOnline::availableFor). Runs after `company` (and `company.can:salon.manage`), before any validation.
 */
class EnsureSalonOnline
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(SalonOnline::availableFor($this->tenancy->require()), 404);

        return $next($request);
    }
}
