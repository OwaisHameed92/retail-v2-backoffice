<?php

namespace App\Http\Middleware;

use App\Domain\Licensing\LicenceKey;
use App\Domain\Shared\Exceptions\ApiException;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Licence API rate limits (docs/specs/licence-api-v1.md): 30 requests a minute per IP and, when the body has a
 * licence key, 10 a minute per key (counted on a hash of the key, never the key). Over a limit → 429
 * `rate_limited` with `retryAfterSeconds`. Every request counts, including failed ones, so guessing keys is slow.
 */
class ThrottleLicenceApi
{
    public const PER_KEY = 10;

    public const PER_IP = 30;

    public const DECAY_SECONDS = 60;

    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $limits = ['licence-api:ip:'.sha1((string) $request->ip()) => self::PER_IP];

        $key = $request->json('licenceKey');
        if (is_string($key) && $key !== '') {
            $limits['licence-api:key:'.self::keyBucket($key)] = self::PER_KEY;
        }

        foreach ($limits as $bucket => $max) {
            if ($this->limiter->tooManyAttempts($bucket, $max)) {
                throw ApiException::rateLimited(max(1, $this->limiter->availableIn($bucket)));
            }
        }

        foreach (array_keys($limits) as $bucket) {
            $this->limiter->hit($bucket, self::DECAY_SECONDS);
        }

        return $next($request);
    }

    /**
     * The bucket of a key: its HMAC when it parses (so every spelling of one key shares a bucket), else a hash of
     * what was typed.
     */
    private static function keyBucket(#[\SensitiveParameter] string $input): string
    {
        return LicenceKey::tryParse($input)?->hash() ?? hash('sha256', LicenceKey::normalise($input));
    }
}
