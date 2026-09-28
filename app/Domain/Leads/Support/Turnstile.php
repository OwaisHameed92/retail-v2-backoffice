<?php

namespace App\Domain\Leads\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cloudflare Turnstile server-side check for the public trial form (module 1.10). Without `TURNSTILE_SECRET` the
 * check is skipped in local and testing (with a log line) and fails everywhere else, so a missing secret in
 * production never lets spam through. A Cloudflare outage counts as a failed check.
 */
final class Turnstile
{
    public function passes(?string $token, ?string $ip): bool
    {
        $secret = (string) config('services.turnstile.secret');

        if ($secret === '') {
            if (app()->environment('local', 'testing')) {
                Log::info('Turnstile check skipped: TURNSTILE_SECRET is not set (local/testing only).');

                return true;
            }

            Log::error('Turnstile check failed: TURNSTILE_SECRET is not set, so public trial requests are refused.');

            return false;
        }

        if ($token === null || $token === '') {
            return false;
        }

        try {
            $reply = Http::asForm()->timeout(5)->post((string) config('services.turnstile.verify_url'), array_filter([
                'secret' => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ]));
        } catch (Throwable $e) {
            Log::warning('Turnstile check could not reach Cloudflare.', ['error' => $e::class]);

            return false;
        }

        return $reply->successful() && $reply->json('success') === true;
    }
}
