<?php

use App\Domain\Shared\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\AssignTraceId;
use App\Http\Middleware\EnsureCompanyAbility;
use App\Http\Middleware\EnsureCompanyMember;
use App\Http\Middleware\EnsureCountryFeature;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Support\InertiaErrorPages;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')->prefix('admin')->name('admin.')
                ->group(base_path('routes/admin.php'));

            Route::middleware('web')->prefix('app')->name('app.')
                ->group(base_path('routes/app.php'));

            // GoCardless webhooks and signed Direct Debit links (module 1.12).
            Route::group([], base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            SecurityHeaders::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Proxies that may set X-Forwarded-*: config/trustedproxy.php (TRUSTED_PROXIES, security review M3), read by the
        // framework's global TrustProxies middleware. Default: none.

        // Till APIs: trace id on every request (module 0.5).
        $middleware->api(append: [
            AssignTraceId::class,
        ]);

        // A sync push body (up to 5,000 rows) is read raw by PushChanges: never decode and walk it twice (module 2.2).
        $middleware->trimStrings(except: [fn (Request $request) => $request->is('api/v1/sync/*')]);
        $middleware->convertEmptyStringsToNull(except: [fn (Request $request) => $request->is('api/v1/sync/*')]);

        // Tenant portal (module 0.4).
        $middleware->alias([
            'company' => EnsureCompanyMember::class,
            'company.can' => EnsureCompanyAbility::class,
            // Two-factor sign-in step (`two-factor:web` after `company`; `two-factor:admin`).
            'two-factor' => RequireTwoFactor::class,
            // A country-only feature (`country.feature:vatReturn`): 404 where the profile has it off (P3).
            'country.feature' => EnsureCountryFeature::class,
        ]);

        // Guests on /admin go to the admin login; everyone else to the tenant login.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('admin', 'admin/*') ? route('admin.login') : route('login'));

        // A signed-in customer on a guest page (sign-in, password reset) goes to their portal, never to `/`, which
        // sends to the sign-in again (the /login ⇄ / loop).
        $middleware->redirectUsersTo(fn () => route('app.dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // api/* errors → {code, message, traceId, retryAfterSeconds, rejectedKey} (module 0.5).
        ApiExceptionRenderer::register($exceptions);

        // Licence keys sent back by the "Email this key" dialog must never be flashed to the session (module 1.3).
        $exceptions->dontFlash(['licences', 'licence_key', 'licenceKey']);

        // Till staff PINs and fob codes (module 4.5) are never kept in the session either.
        $exceptions->dontFlash(['pin', 'pin_confirmation', 'rfid']);

        // Branded 403/404/500/503 pages for the portal and admin; JSON callers untouched (module 7.1).
        InertiaErrorPages::register($exceptions);
    })->create();
