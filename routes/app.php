<?php

// Tenant portal area. Loaded under the /app prefix, `app.` name prefix and the "web" middleware group
// (see bootstrap/app.php). Every route here must sit behind `auth`, `verified` and `company`; add
// `company.can:<ability>` for anything role-restricted.

use App\Http\Controllers\App\BillingController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\InvitationAcceptController;
use App\Http\Controllers\App\PortalInvitationController;
use App\Http\Controllers\App\PortalUserController;
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

    // Module 4.1: portal users, invitations and the role matrix. Owner only.
    Route::prefix('users')->name('users.')->middleware('company.can:users.manage')->group(function () {
        Route::get('/', [PortalUserController::class, 'index'])->name('index');
        Route::put('{user}', [PortalUserController::class, 'update'])->name('update')->whereNumber('user');
        Route::post('{user}/deactivate', [PortalUserController::class, 'deactivate'])->name('deactivate')->whereNumber('user');
        Route::post('{user}/reactivate', [PortalUserController::class, 'reactivate'])->name('reactivate')->whereNumber('user');
        Route::delete('{user}', [PortalUserController::class, 'destroy'])->name('destroy')->whereNumber('user');
        Route::post('invitations', [PortalInvitationController::class, 'store'])->name('invitations.store')->middleware('throttle:30,1');
        Route::post('invitations/{invitation}/resend', [PortalInvitationController::class, 'resend'])->name('invitations.resend')->whereUlid('invitation')->middleware('throttle:10,1');
        Route::delete('invitations/{invitation}', [PortalInvitationController::class, 'destroy'])->name('invitations.destroy')->whereUlid('invitation');
    });
});

// Module 4.1: the emailed invitation link. Guests and signed-in users (the invitee is not a member yet); the signature
// and token are checked by the controller. GET shows it, POST accepts, DELETE signs another account out ("Not you?").
Route::prefix('invitations/{invitation}/{token}')->name('invitations.')->where(['invitation' => '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}', 'token' => '[A-Za-z0-9]{20,100}'])
    ->middleware('throttle:30,1')->group(function () {
        Route::get('/', [InvitationAcceptController::class, 'show'])->name('show');
        Route::post('/', [InvitationAcceptController::class, 'accept'])->name('accept');
        Route::delete('/', [InvitationAcceptController::class, 'switchAccount'])->name('switch-account');
    });
