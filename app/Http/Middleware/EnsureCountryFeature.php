<?php

namespace App\Http\Middleware;

use App\Domain\Shared\Country\Country;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `country.feature`, e.g. `country.feature:vatReturn` (Pakistan plan P3). A route for a country-only feature
 * (the HMRC VAT return is UK-only) answers 404 on an instance whose profile has the feature off, as if it did not exist.
 */
class EnsureCountryFeature
{
    public function __construct(private readonly Country $country) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless($this->country->feature($feature), 404);

        return $next($request);
    }
}
