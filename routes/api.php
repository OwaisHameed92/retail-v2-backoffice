<?php

use App\Http\Controllers\Api\LicenceApiController;
use App\Http\Middleware\EnsureLicenceContract;
use App\Http\Middleware\ThrottleLicenceApi;
use Illuminate\Support\Facades\Route;

// Till APIs, loaded under the /api prefix with the "api" middleware group.
// Licence API (Phase 1, module 1.5): /api/v1/licence/*
// Sync API (Phase 2): /api/v1/sync/*

// Licence API v1 (module 1.5, docs/specs/licence-api-v1.md): rate limited (10/min per key, 30/min per IP),
// `X-SSPOS-Licence-Contract: 1` required, key only in the JSON body.
Route::prefix('v1/licence')->name('api.licence.')
    ->middleware([ThrottleLicenceApi::class, EnsureLicenceContract::class])
    ->controller(LicenceApiController::class)
    ->group(function () {
        Route::post('activate', 'activate')->name('activate');
        Route::post('check-in', 'checkIn')->name('check-in');
        Route::post('deactivate', 'deactivate')->name('deactivate');
        Route::get('keys', 'keys')->name('keys');
    });
