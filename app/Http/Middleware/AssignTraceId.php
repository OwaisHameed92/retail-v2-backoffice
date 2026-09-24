<?php

namespace App\Http\Middleware;

use App\Domain\Shared\Support\TraceId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every API request a ULID trace id: stored on the request, added to the log context and returned
 * in the `X-Trace-Id` header. The error body uses the same id.
 */
class AssignTraceId
{
    public function handle(Request $request, Closure $next): Response
    {
        $traceId = TraceId::for($request);

        Log::withContext(['traceId' => $traceId]);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(TraceId::HEADER, $traceId);

        return $response;
    }
}
