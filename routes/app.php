<?php

// Tenant portal area. Loaded under the /app prefix, `app.` name prefix and the "web" middleware group
// (see bootstrap/app.php). Every route here must sit behind `auth`, `verified` and `company`; add
// `company.can:<ability>` for anything role-restricted.

use App\Http\Controllers\App\BillingController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\SwitchBranchController;
use App\Http\Controllers\App\SwitchCompanyController;
use App\Http\Controllers\App\SyncConflictController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'verified', 'company'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::post('company/switch', SwitchCompanyController::class)->name('company.switch');
    Route::post('branch/switch', SwitchBranchController::class)->name('branch.switch');

    // Module 1.13: plan, pricing, Direct Debit (set up by the owner: billing.manage) and invoices. Open while suspended.
    Route::prefix('billing')->name('billing')->middleware('company.can:billing.view')->group(function () {
        Route::get('/', [BillingController::class, 'index']);
        Route::middleware('company.can:billing.manage')->group(function () {
            Route::post('direct-debit', [BillingController::class, 'startDirectDebit'])->name('.direct-debit')->middleware('throttle:10,1');
            Route::get('direct-debit/return', [BillingController::class, 'directDebitReturn'])->name('.direct-debit.return');
        });
        Route::get('invoices/{invoice}/pdf', [BillingController::class, 'invoicePdf'])->name('.invoices.pdf')->whereUlid('invoice')->middleware('throttle:60,1');
    });

    // Module 2.9B: sync conflicts (a shop's change the portal kept out) and the tills' own clashes. Owner and manager.
    Route::prefix('sync')->name('sync.')->middleware('company.can:sync.manage')->group(function () {
        Route::get('conflicts', [SyncConflictController::class, 'index'])->name('conflicts.index');
        Route::get('conflicts/{conflict}', [SyncConflictController::class, 'show'])->name('conflicts.show')->whereUlid('conflict');
        Route::post('conflicts/{conflict}/resolve', [SyncConflictController::class, 'resolve'])->name('conflicts.resolve')->whereUlid('conflict')->middleware('throttle:60,1');
        Route::get('clashes/{clash}', [SyncConflictController::class, 'clash'])->name('clashes.show')->whereUlid('clash');
    });
});
