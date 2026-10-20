<?php

namespace App\Domain\Handheld\Support;

use App\Domain\Handheld\Models\HandheldToken;
use App\Http\Middleware\AuthenticateHandheldToken;
use App\Http\Middleware\ThrottleHandheldApi;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The handheld API's own named limiters, applied by ThrottleHandheldApi (never the framework `throttle:`, whose
 * buckets other routes share and which runs before the token is known):
 *
 * - `pda-login`: `handheld.login_per_minute` (10) per email + IP, and `handheld.login_per_ip_per_minute` (60) per IP.
 * - `pda-api`: `handheld.api_per_minute` (240) per token, after AuthenticateHandheldToken.
 *
 * Buckets hold SHA-256 hashes, never an email or a token. Over a limit → 429 RATE_LIMITED with Retry-After.
 *
 * @see ThrottleHandheldApi
 */
final class HandheldRateLimits
{
    public const LOGIN = 'pda-login';

    public const API = 'pda-api';

    public static function register(): void
    {
        RateLimiter::for(self::LOGIN, function (Request $request) {
            $ip = hash('sha256', (string) $request->ip());
            $email = hash('sha256', mb_strtolower(trim((string) $request->input('email'))));

            return [
                Limit::perMinute(max(1, (int) config('handheld.login_per_minute', 10)))->by("email:{$email}|ip:{$ip}"),
                Limit::perMinute(max(1, (int) config('handheld.login_per_ip_per_minute', 60)))->by("ip:{$ip}"),
            ];
        });

        RateLimiter::for(self::API, function (Request $request) {
            $token = $request->attributes->get(AuthenticateHandheldToken::TOKEN);

            return Limit::perMinute(max(1, (int) config('handheld.api_per_minute', 240)))
                ->by($token instanceof HandheldToken ? 'token:'.$token->id : 'ip:'.hash('sha256', (string) $request->ip()));
        });
    }
}
