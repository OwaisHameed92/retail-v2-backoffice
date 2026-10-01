<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware(['auth', 'company', 'two-factor:web'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    // Two-factor sign-in (own account) and, for the owner, requiring it for everyone in the business.
    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');
    Route::delete('settings/security/two-factor', [SecurityController::class, 'disable'])->name('security.two-factor.destroy')->middleware('throttle:10,1');
    Route::post('settings/security/recovery-codes', [SecurityController::class, 'recoveryCodes'])->name('security.recovery-codes')->middleware('throttle:10,1');
    Route::put('settings/security/company', [SecurityController::class, 'company'])->name('security.company')->middleware(['company.can:business.manage', 'throttle:30,1']);

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');
});
