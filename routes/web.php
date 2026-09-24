<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

// The customer dashboard lives at /app (routes/app.php).

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
