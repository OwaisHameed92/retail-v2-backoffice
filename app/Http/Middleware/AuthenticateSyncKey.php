<?php

namespace App\Http\Middleware;

use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Sync\Actions\AuthenticateSyncRequest;
use App\Domain\Sync\Data\SyncCaller;
use App\Domain\Sync\Support\SyncAuthLimiter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/api/v1/sync/*` (module 2.1, ready for 2.2): `Authorization: Bearer <branch sync key>` + the identity headers
 * of contract §3. Errors are ApiExceptions (401 auth.invalid_key / auth.key_revoked, 403 auth.wrong_branch),
 * rendered as the §9 envelope. Failed checks are throttled per IP and per key (SyncAuthLimiter, security review M6).
 * Controllers read the caller with `AuthenticateSyncKey::caller($request)`.
 */
class AuthenticateSyncKey
{
    public const ATTRIBUTE = 'sspos.syncCaller';

    public function __construct(
        private readonly AuthenticateSyncRequest $authenticate,
        private readonly SyncAuthLimiter $failures,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->failures->ensureAllowed($request->ip(), $request->bearerToken());

        try {
            $caller = $this->authenticate->handle(
                $request->bearerToken(),
                $request->header('X-SSPOS-Company-Id'),
                $request->header('X-SSPOS-Branch-Id'),
                $request->header('X-SSPOS-Register-Id'),
            );
        } catch (ApiException $e) {
            $this->failures->failed($request->ip(), $request->bearerToken());

            throw $e;
        }

        $request->attributes->set(self::ATTRIBUTE, $caller);

        return $next($request);
    }

    public static function caller(Request $request): SyncCaller
    {
        $caller = $request->attributes->get(self::ATTRIBUTE);

        if (! $caller instanceof SyncCaller) {
            throw new \LogicException('The route is not behind AuthenticateSyncKey.');
        }

        return $caller;
    }
}
