<?php

// Super admin area. Loaded under the /admin prefix with the "web" middleware group and the
// "admin." route name prefix (see bootstrap/app.php).

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Mail\Models\EmailLog;
use App\Domain\Tenancy\Models\Company;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EmailLogController;
use App\Http\Controllers\Admin\EmailTemplateController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\PlanController;
use App\Http\Controllers\Admin\PlanLifecycleController;
use App\Http\Controllers\Admin\TenantBranchController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Admin\TenantRegisterController;
use App\Http\Controllers\Admin\TenantStatusController;
use App\Http\Controllers\Admin\TenantUserController;
use App\Http\Middleware\AdminIsActive;
use App\Http\Middleware\BlockAdminWhileImpersonating;
use App\Http\Middleware\ShareAdminInertiaData;
use Illuminate\Support\Facades\Route;

Route::get('login', [LoginController::class, 'create'])->name('login');
Route::post('login', [LoginController::class, 'store'])->name('login.store');

// "Login as customer" ends here. Outside the main group: the admin area is blocked while impersonating (module 1.2).
Route::post('impersonation/stop', [ImpersonationController::class, 'destroy'])
    ->middleware(['auth:admin', AdminIsActive::class])->name('impersonation.stop');

Route::middleware(['auth:admin', AdminIsActive::class, BlockAdminWhileImpersonating::class, ShareAdminInertiaData::class])->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');

    Route::prefix('admins')->name('admins.')->controller(AdminUserController::class)->group(function () {
        Route::get('/', 'index')->name('index')->can('viewAny', Admin::class);
        Route::get('create', 'create')->name('create')->can('create', Admin::class);
        Route::post('/', 'store')->name('store')->can('create', Admin::class);
        Route::get('{admin}/edit', 'edit')->name('edit')->can('update', 'admin');
        Route::put('{admin}', 'update')->name('update')->can('update', 'admin');
        Route::post('{admin}/deactivate', 'deactivate')->name('deactivate')->can('deactivate', 'admin');
        Route::post('{admin}/reactivate', 'reactivate')->name('reactivate')->can('reactivate', 'admin');
    });

    // Tenants (module 1.2): tenants.view reads, tenants.manage changes (CompanyPolicy).
    Route::prefix('tenants')->name('tenants.')->group(function () {
        Route::get('/', [TenantController::class, 'index'])->name('index')->can('viewAny', Company::class);
        Route::get('create', [TenantController::class, 'create'])->name('create')->can('create', Company::class);
        Route::post('/', [TenantController::class, 'store'])->name('store')->can('create', Company::class);
        Route::get('{company}', [TenantController::class, 'show'])->name('show')->can('view', 'company');

        Route::middleware('can:update,company')->group(function () {
            Route::get('{company}/edit', [TenantController::class, 'edit'])->name('edit');
            Route::put('{company}', [TenantController::class, 'update'])->name('update');

            Route::post('{company}/activate', [TenantStatusController::class, 'activate'])->name('activate');
            Route::post('{company}/suspend', [TenantStatusController::class, 'suspend'])->name('suspend');
            Route::post('{company}/unsuspend', [TenantStatusController::class, 'unsuspend'])->name('unsuspend');
            Route::post('{company}/cancel', [TenantStatusController::class, 'cancel'])->name('cancel');

            Route::post('{company}/branches', [TenantBranchController::class, 'store'])->name('branches.store');
            Route::put('{company}/branches/{branch}', [TenantBranchController::class, 'update'])->name('branches.update');
            Route::post('{company}/branches/{branch}/deactivate', [TenantBranchController::class, 'deactivate'])->name('branches.deactivate');
            Route::post('{company}/branches/{branch}/reactivate', [TenantBranchController::class, 'reactivate'])->name('branches.reactivate');
            Route::post('{company}/branches/{branch}/registers', [TenantRegisterController::class, 'store'])->name('registers.store');

            Route::put('{company}/registers/{register}', [TenantRegisterController::class, 'update'])->name('registers.update');
            Route::post('{company}/registers/{register}/main', [TenantRegisterController::class, 'main'])->name('registers.main');
            Route::post('{company}/registers/{register}/deactivate', [TenantRegisterController::class, 'deactivate'])->name('registers.deactivate');
            Route::post('{company}/registers/{register}/reactivate', [TenantRegisterController::class, 'reactivate'])->name('registers.reactivate');

            Route::post('{company}/users', [TenantUserController::class, 'store'])->name('users.store');
            Route::put('{company}/users/{user}', [TenantUserController::class, 'update'])->name('users.update')->whereNumber('user');
            Route::delete('{company}/users/{user}', [TenantUserController::class, 'destroy'])->name('users.destroy')->whereNumber('user');
            Route::post('{company}/users/{user}/password-link', [TenantUserController::class, 'sendPasswordLink'])->name('users.password-link')->whereNumber('user');
        });

        Route::post('{company}/impersonate', [ImpersonationController::class, 'store'])->name('impersonate')->can('impersonate', 'company');
    });

    // Emails (module 1.7): log, template previews and test sends. Owner and support (EmailLogPolicy).
    Route::prefix('emails')->name('emails.')->middleware('can:viewAny,'.EmailLog::class)->group(function () {
        Route::get('/', [EmailLogController::class, 'index'])->name('index');
        Route::get('templates', [EmailTemplateController::class, 'index'])->name('templates');
        Route::get('templates/{template}/preview', [EmailTemplateController::class, 'preview'])->name('templates.preview')->where('template', '[a-z0-9-]+');
        Route::post('templates/{template}/test', [EmailTemplateController::class, 'sendTest'])->name('templates.test')->where('template', '[a-z0-9-]+')
            ->middleware(['can:sendTest,'.EmailLog::class, 'throttle:10,1']);
    });

    // Plans (module 1.1). Archived plans stay viewable, restorable and copyable.
    Route::prefix('plans')->name('plans.')->middleware('can:'.AdminRole::BILLING_MANAGE)->group(function () {
        Route::get('/', [PlanController::class, 'index'])->name('index');
        Route::get('create', [PlanController::class, 'create'])->name('create');
        Route::post('/', [PlanController::class, 'store'])->name('store');
        Route::get('{plan}', [PlanController::class, 'show'])->name('show')->withTrashed();
        Route::get('{plan}/edit', [PlanController::class, 'edit'])->name('edit');
        Route::put('{plan}', [PlanController::class, 'update'])->name('update');
        Route::delete('{plan}', [PlanLifecycleController::class, 'archive'])->name('archive');
        Route::post('{plan}/restore', [PlanLifecycleController::class, 'restore'])->name('restore')->withTrashed();
        Route::post('{plan}/duplicate', [PlanLifecycleController::class, 'duplicate'])->name('duplicate')->withTrashed();
    });
});
