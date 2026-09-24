<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `company.can`, e.g. `company.can:catalogue.manage`. Must run after `company`.
 * Responds 403 when the user's role in the current company lacks the ability.
 */
class EnsureCompanyAbility
{
    public function __construct(private readonly CurrentCompany $currentCompany) {}

    public function handle(Request $request, Closure $next, string $ability): Response
    {
        abort_unless($this->currentCompany->can($ability), 403);

        return $next($request);
    }
}
