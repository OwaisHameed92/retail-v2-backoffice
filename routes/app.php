<?php

// Tenant portal area. Loaded under the /app prefix, `app.` name prefix and the "web" middleware group
// (see bootstrap/app.php). Every route here must sit behind `auth`, `verified` and `company`; add
// `company.can:<ability>` for anything role-restricted.

use App\Http\Controllers\App\BillingController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\StaffController;
use App\Http\Controllers\App\SupplierController;
use App\Http\Controllers\App\SwitchBranchController;
use App\Http\Controllers\App\SwitchCompanyController;
use App\Http\Controllers\App\SyncConflictController;
use App\Http\Controllers\App\TillListController;
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

    // Module 4.5: suppliers, payment types and reasons, till staff (PINs, fobs) and till role permissions. Owner and
    // manager; a one-shop user may look but not change (CompanyWideWriteRequest).
    Route::prefix('suppliers')->name('suppliers.')->middleware('company.can:suppliers.manage')->group(function () {
        Route::get('/', [SupplierController::class, 'index'])->name('index');
        Route::get('create', [SupplierController::class, 'create'])->name('create');
        Route::post('/', [SupplierController::class, 'store'])->name('store')->middleware('throttle:60,1');
        Route::get('{supplier}/edit', [SupplierController::class, 'edit'])->name('edit')->whereUlid('supplier');
        Route::put('{supplier}', [SupplierController::class, 'update'])->name('update')->whereUlid('supplier')->middleware('throttle:60,1');
        Route::delete('{supplier}', [SupplierController::class, 'destroy'])->name('destroy')->whereUlid('supplier')->middleware('throttle:60,1');
    });
    Route::middleware('company.can:settings.manage')->group(function () {
        Route::get('payment-types', [TillListController::class, 'paymentTypes'])->name('payment-types.index');
        Route::post('payment-types', [TillListController::class, 'storePaymentType'])->name('payment-types.store')->middleware('throttle:60,1');
        Route::put('payment-types/{paymentType}', [TillListController::class, 'updatePaymentType'])->name('payment-types.update')->whereUlid('paymentType')->middleware('throttle:60,1');
        Route::delete('payment-types/{paymentType}', [TillListController::class, 'destroyPaymentType'])->name('payment-types.destroy')->whereUlid('paymentType')->middleware('throttle:60,1');
        Route::get('reasons', [TillListController::class, 'reasons'])->name('reasons.index');
        Route::post('reasons', [TillListController::class, 'storeReason'])->name('reasons.store')->middleware('throttle:60,1');
        Route::put('reasons/{reason}', [TillListController::class, 'updateReason'])->name('reasons.update')->whereUlid('reason')->middleware('throttle:60,1');
        Route::delete('reasons/{reason}', [TillListController::class, 'destroyReason'])->name('reasons.destroy')->whereUlid('reason')->middleware('throttle:60,1');
    });
    Route::prefix('staff')->name('staff.')->middleware('company.can:staff.manage')->group(function () {
        Route::get('/', [StaffController::class, 'index'])->name('index');
        Route::get('create', [StaffController::class, 'create'])->name('create');
        Route::post('/', [StaffController::class, 'store'])->name('store')->middleware('throttle:30,1');
        Route::get('roles', [StaffController::class, 'roles'])->name('roles');
        Route::put('roles/{role}', [StaffController::class, 'updateRole'])->name('roles.update')->whereUlid('role')->middleware('throttle:60,1');
        Route::get('{staff}/edit', [StaffController::class, 'edit'])->name('edit')->whereUlid('staff');
        Route::put('{staff}', [StaffController::class, 'update'])->name('update')->whereUlid('staff')->middleware('throttle:60,1');
        Route::put('{staff}/pin', [StaffController::class, 'pin'])->name('pin')->whereUlid('staff')->middleware('throttle:10,1');
        Route::put('{staff}/fob', [StaffController::class, 'fob'])->name('fob')->whereUlid('staff')->middleware('throttle:30,1');
        Route::delete('{staff}', [StaffController::class, 'destroy'])->name('destroy')->whereUlid('staff')->middleware('throttle:60,1');
    });
});
