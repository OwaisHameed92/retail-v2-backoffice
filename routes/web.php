<?php

use App\Http\Controllers\TrialPageController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

// Hosted trial request form (module 1.10): posts to the public API, until and alongside the marketing website.
Route::get('trial', TrialPageController::class)->name('trial');

// The customer dashboard lives at /app (routes/app.php).

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
