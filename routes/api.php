<?php

use App\Domain\Handheld\Support\HandheldRateLimits;
use App\Http\Controllers\Api\CloudMigrationController;
use App\Http\Controllers\Api\LicenceApiController;
use App\Http\Controllers\Api\PartnerTenantRequestController;
use App\Http\Controllers\Api\Pda\PdaAuthController;
use App\Http\Controllers\Api\Pda\PdaCatalogueController;
use App\Http\Controllers\Api\Pda\PdaProductChangeController;
use App\Http\Controllers\Api\Pda\PdaPurchaseOrderController;
use App\Http\Controllers\Api\Pda\PdaPurchaseReturnController;
use App\Http\Controllers\Api\Pda\PdaReceivingController;
use App\Http\Controllers\Api\Pda\PdaStockAdjustmentController;
use App\Http\Controllers\Api\Pda\PdaStockTakeController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\TrialRequestController;
use App\Http\Middleware\AuditPartnerCall;
use App\Http\Middleware\AuthenticateHandheldToken;
use App\Http\Middleware\AuthenticatePartnerKey;
use App\Http\Middleware\AuthenticateSyncKey;
use App\Http\Middleware\EnsureTillContract;
use App\Http\Middleware\GuardPublicTrialRequests;
use App\Http\Middleware\GuardSyncRequest;
use App\Http\Middleware\IdempotentTillRequest;
use App\Http\Middleware\PublicFormCors;
use App\Http\Middleware\RequireStockDrafts;
use App\Http\Middleware\ThrottleHandheldApi;
use App\Http\Middleware\ThrottleLicenceApi;
use App\Http\Middleware\ThrottlePartnerApi;
use Illuminate\Support\Facades\Route;

// Till APIs, loaded under the /api prefix with the "api" middleware group.
// Sync API (Phase 2, contract v1.4.1 §3-4, §7, §9): `X-SSPOS-Contract: 1` (409 otherwise, echoed on every reply),
// Bearer branch sync key checked against X-SSPOS-Branch-Id via id_map (module 2.1), the other §3 headers and a
// per-key rate limit. Module 2.2: hello, push; 2.5: pull.
Route::prefix('v1/sync')->name('api.sync.')
    ->middleware([EnsureTillContract::class, AuthenticateSyncKey::class, GuardSyncRequest::class])
    ->controller(SyncController::class)
    ->group(function () {
        Route::get('hello', 'hello')->name('hello');
        Route::post('push', 'push')->name('push');
        Route::get('pull', 'pull')->name('pull');
    });

// Per-till licensing, EPOS contract v1.4.1 (docs/contracts/portal-api-v1.4.1/docs/web-portal-api.md §17.15, §17.7):
// `X-SSPOS-Contract: 1` required, rate limited (§17.12), `Idempotency-Key` replayed (§17.11), no branch key
// (`licence/redeem`, module 2.8: a linked till's branch key is checked by the action; a local key report has none).
Route::prefix('v1')->name('api.')
    ->middleware([EnsureTillContract::class, ThrottleLicenceApi::class, IdempotentTillRequest::class])
    ->controller(LicenceApiController::class)
    ->group(function () {
        Route::post('licence/activate', 'activate')->name('licence.activate');
        Route::post('licence/validate', 'validateLicence')->name('licence.validate');
        Route::post('licence/redeem', 'redeem')->name('licence.redeem');
        Route::post('devices/deactivate', 'deactivate')->name('devices.deactivate');
    });

// Module 2.8, a shop moving to the cloud (§17.8): `cloud/migrate` has no Authorization (the sync key in the body is
// the credential: the licence group's rate limits and wrong-code limit); `migrate/complete` is Bearer the branch key,
// like sync/*, with Idempotency-Key replay.
Route::prefix('v1/cloud')->name('api.cloud.')->controller(CloudMigrationController::class)->group(function () {
    Route::post('migrate', 'migrate')->name('migrate')
        ->middleware([EnsureTillContract::class, ThrottleLicenceApi::class, IdempotentTillRequest::class]);
    Route::post('migrate/complete', 'complete')->name('migrate.complete')
        ->middleware([EnsureTillContract::class, AuthenticateSyncKey::class, GuardSyncRequest::class, IdempotentTillRequest::class]);
});

// Public trial form (module 1.10, docs/specs/public-trial-api.md): our marketing website and the hosted /trial page.
// No auth. CORS allow-list (PUBLIC_FORM_ORIGINS), 5 an hour per IP, honeypot, Turnstile and 3 a day per email.
Route::prefix('v1/public')->name('api.public.')->middleware(PublicFormCors::class)
    ->controller(TrialRequestController::class)
    ->group(function () {
        Route::options('trial-requests', 'preflight')->name('trial-requests.preflight');
        Route::post('trial-requests', 'store')->name('trial-requests.store')->middleware(GuardPublicTrialRequests::class);
    });

// Partner onboarding API v1 (docs/partner-api.md): Zivaro → retail portal. Bearer partner key (AuthenticatePartnerKey),
// its own named limiter (60 a minute per key, PartnerRateLimit), every call's outcome audited (AuditPartnerCall,
// outermost). A request never creates a business: it waits in Admin → Partner requests. UK only: 404 where the
// country profile has `partnerApi` off (PK).
Route::prefix('partner/v1')->name('api.partner.')
    ->middleware(['country.feature:partnerApi', AuditPartnerCall::class, AuthenticatePartnerKey::class, ThrottlePartnerApi::class])
    ->controller(PartnerTenantRequestController::class)
    ->group(function () {
        Route::get('plans', 'plans')->name('plans');
        Route::put('tenant-requests/{externalId}', 'upsert')->name('tenant-requests.upsert');
        Route::get('tenant-requests/{externalId}', 'show')->name('tenant-requests.show');
        Route::post('tenant-requests/{externalId}/cancel', 'cancel')->name('tenant-requests.cancel');
    });

// Handheld (PDA) API, phase 1 + the portal part of phase 2 (docs/pda-api.md): the Android PDA app, same paths and shapes as the legacy portal's
// /api/pda/*. Login has its own limiter per email + IP; every other call needs `Authorization: Bearer <token>`
// (AuthenticateHandheldToken: never a query string) and is limited per token. Everything is scoped to the user's
// business and shop. Errors: `{success: false, code, message, traceId, retryAfterSeconds, rejectedKey}`. Both
// countries; only on plans that include the handheld app (DECISIONS "Handheld (PDA) API", "Handheld app on monthly plans").
Route::prefix('pda')->name('api.pda.')->group(function () {
    Route::post('login', [PdaAuthController::class, 'login'])->name('login')
        ->middleware(ThrottleHandheldApi::class.':'.HandheldRateLimits::LOGIN);

    Route::middleware([AuthenticateHandheldToken::class, ThrottleHandheldApi::class.':'.HandheldRateLimits::API])->group(function () {
        Route::get('me', [PdaAuthController::class, 'me'])->name('me');
        Route::post('logout', [PdaAuthController::class, 'logout'])->name('logout');

        Route::controller(PdaCatalogueController::class)->group(function () {
            Route::get('products', 'products')->name('products.index');
            Route::get('products/{product}/units', 'units')->name('products.units')->whereUlid('product');
            Route::put('products/{product}/price', 'updatePrice')->name('products.price')->whereUlid('product');
            Route::get('resolve-barcode', 'resolveBarcode')->name('resolve-barcode');
            Route::get('suppliers', 'suppliers')->name('suppliers.index');
        });

        // Phase 2 (portal part): this shop's own price, unit prices and the cost, through the portal's own paths.
        Route::controller(PdaProductChangeController::class)->prefix('products/{product}')->name('products.')->whereUlid('product')->group(function () {
            Route::put('shop-price', 'shopPrice')->name('shop-price');
            Route::put('units/{unit}/price', 'unitPrice')->name('units.price')->whereUlid('unit');
            Route::put('cost', 'cost')->name('cost');
        });

        Route::controller(PdaPurchaseOrderController::class)->prefix('purchase-orders')->name('purchase-orders.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::get('{order}', 'show')->name('show')->whereUlid('order');
            Route::put('{order}', 'update')->name('update')->whereUlid('order');
            Route::post('{order}/cancel', 'cancel')->name('cancel')->whereUlid('order');
        });

        // Stock documents drafted for the shop's till (contract proposal docs/contracts/proposals/pda-stock-drafts):
        // RequireStockDrafts answers 501 NOT_AVAILABLE_YET while PDA_STOCK_DRAFTS is off (the default), exactly as
        // before. Stock locations are not in the model (not routed).
        Route::middleware(RequireStockDrafts::class)->group(function () {
            Route::controller(PdaReceivingController::class)->prefix('purchase-orders/{order}')->name('purchase-orders.')->group(function () {
                Route::post('receive', 'receive')->name('receive');
                Route::get('receipts', 'receipts')->name('receipts');
                Route::get('returnable', 'returnable')->name('returnable');
            });
            Route::controller(PdaPurchaseReturnController::class)->prefix('purchase-returns')->name('purchase-returns.')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::put('{return}', 'update')->name('update');
                Route::post('{return}/cancel', 'cancel')->name('cancel');
            });
            Route::controller(PdaStockAdjustmentController::class)->prefix('stock-adjustments')->name('stock-adjustments.')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::put('{adjustment}', 'update')->name('update');
                Route::post('{adjustment}/cancel', 'cancel')->name('cancel');
            });
            Route::controller(PdaStockTakeController::class)->prefix('stock-takes')->name('stock-takes.')->group(function () {
                Route::get('/', 'index')->name('index');
                Route::post('/', 'store')->name('store');
                Route::get('{take}', 'show')->name('show');
                Route::put('{take}', 'update')->name('update');
                Route::post('{take}/cancel', 'cancel')->name('cancel');
            });
        });
    });
});
