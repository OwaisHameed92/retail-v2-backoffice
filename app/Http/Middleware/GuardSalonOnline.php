<?php

namespace App\Http\Middleware;

use App\Domain\Salon\Support\SalonApiErrors;
use App\Domain\Salon\Support\SalonOnlineSwitch;
use App\Domain\Salon\Support\SalonShops;
use App\Domain\Sync\Support\SyncApiErrors;
use App\Domain\Tenancy\Models\Register;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/api/v1/sync/salon/*` after the sync key check (AuthenticateSyncKey, GuardSyncRequest), salon pack 2026-11-08
 * README §3 (W3a), in this order:
 *
 * 1. SALON_ONLINE off (the default) → 501 salon.not_available with Retry-After 3600, before the body is read. An
 *    unknown key is still 401 first: the till learns nothing before it proves who it is.
 * 2. Its own limit, `salon.online_per_minute` calls per sync key (60) → 429 rate.limited with Retry-After, on top of
 *    the key's general limit, so salon calls never crowd out push and pull.
 * 3. Only a salon shop's main (or single) till: the key's shop must be a salon on our side (SalonShops) and the
 *    calling register, when we know it, the shop's main till → else 403 salon.not_a_salon. (The sync key only ever
 *    goes to the main till; a till we have not mapped yet is taken as the key's holder.)
 */
class GuardSalonOnline
{
    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! SalonOnlineSwitch::enabled()) {
            throw SalonApiErrors::notAvailable();
        }

        $caller = AuthenticateSyncKey::caller($request);
        $bucket = 'sync-salon:key:'.$caller->syncKeyId;

        if ($this->limiter->tooManyAttempts($bucket, SalonOnlineSwitch::perMinute())) {
            throw SyncApiErrors::rateLimited($this->limiter->availableIn($bucket));
        }

        $this->limiter->hit($bucket, 60);

        if (! SalonShops::isSalonBranch($caller->company->id, $caller->branch->id) || ! self::mainTill($caller->registerId)) {
            throw SalonApiErrors::notASalon();
        }

        return $next($request);
    }

    private static function mainTill(?string $registerId): bool
    {
        if ($registerId === null) {
            return true;
        }

        $register = Register::withoutCompanyScope()->withTrashed()->whereKey($registerId)->first(['id', 'is_main_till', 'deleted_at']);

        return $register === null || ((bool) $register->is_main_till && $register->deleted_at === null);
    }
}
