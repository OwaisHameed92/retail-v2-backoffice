<?php

namespace App\Http\Middleware;

use App\Domain\Leads\Actions\SubmitTrialRequest;
use App\Domain\Leads\Models\Lead;
use App\Domain\Shared\Exceptions\ApiException;
use App\Http\Controllers\Api\TrialRequestController;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anti-spam in front of the public trial form API (module 1.10):
 * - 5 requests an hour per IP (429 `rate.limited`); replies 400 (a typo in the form) do not count;
 * - honeypot: a filled `website` field gets the normal 201 reply, but no lead is created.
 * Buckets hold hashes only.
 */
class GuardPublicTrialRequests
{
    public const IP_LIMIT_PER_HOUR = 5;

    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $bucket = 'trial-request:ip:'.hash('sha256', (string) $request->ip());

        if ($this->limiter->tooManyAttempts($bucket, self::IP_LIMIT_PER_HOUR)) {
            throw new ApiException('rate.limited', SubmitTrialRequest::RATE_LIMITED, 429, $this->limiter->availableIn($bucket));
        }

        $honeypot = $request->input('website');

        if (is_string($honeypot) && trim($honeypot) !== '') {
            $this->limiter->hit($bucket, 3600);
            Log::info('Public trial request dropped: honeypot filled.');

            return TrialRequestController::accepted(Lead::REFERENCE_PREFIX.strtoupper(Str::random(6)));
        }

        $response = $next($request);

        if ($response->getStatusCode() !== 400) {
            $this->limiter->hit($bucket, 3600);
        }

        return $response;
    }
}
