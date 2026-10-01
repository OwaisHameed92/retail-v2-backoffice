<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\TrialPageController;
use Illuminate\Support\Facades\Route;

// No public landing page here (the marketing website has it): the root goes to the sign-in, or to the signed-in
// customer's portal / the signed-in admin's dashboard.
Route::get('/', HomeController::class)->name('home');

// Hosted trial request form (module 1.10): posts to the public API, until and alongside the marketing website.
Route::get('trial', TrialPageController::class)->name('trial');

// Legal pages (module 7.7), public: rendered from resources/legal/<page>.md.
Route::get('legal/{page}', LegalController::class)->name('legal')->whereIn('page', array_keys(LegalController::PAGES))->middleware('throttle:60,1');

// The customer dashboard lives at /app (routes/app.php).

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
