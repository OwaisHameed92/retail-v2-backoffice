<?php

use App\Http\Controllers\Api\CloudMigrationController;
use App\Http\Controllers\Api\LicenceApiController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\TrialRequestController;
use App\Http\Middleware\AuthenticateSyncKey;
use App\Http\Middleware\EnsureTillContract;
use App\Http\Middleware\GuardPublicTrialRequests;
use App\Http\Middleware\GuardSyncRequest;
use App\Http\Middleware\IdempotentTillRequest;
use App\Http\Middleware\PublicFormCors;
use App\Http\Middleware\ThrottleLicenceApi;
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
