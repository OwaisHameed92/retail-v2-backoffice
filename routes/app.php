<?php

// Tenant portal area. Loaded under the /app prefix, `app.` name prefix and the "web" middleware group
// (see bootstrap/app.php). Every route here must sit behind `auth`, `verified` and `company`; add
// `company.can:<ability>` for anything role-restricted.

use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\SwitchBranchController;
use App\Http\Controllers\App\SwitchCompanyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:web', 'verified', 'company'])->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::post('company/switch', SwitchCompanyController::class)->name('company.switch');
    Route::post('branch/switch', SwitchBranchController::class)->name('branch.switch');
});
