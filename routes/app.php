<?php

// Tenant portal area. Loaded under the /app prefix, `app.` name prefix and the "web" middleware group
// (see bootstrap/app.php). Every route here must sit behind `auth`, `verified` and `company`; add
// `company.can:<ability>` for anything role-restricted.

use App\Http\Controllers\App\BillingController;
use App\Http\Controllers\App\CatalogueGroupController;
use App\Http\Controllers\App\CustomerController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\InvitationAcceptController;
use App\Http\Controllers\App\PortalInvitationController;
use App\Http\Controllers\App\PortalUserController;
use App\Http\Controllers\App\PriceController;
use App\Http\Controllers\App\ProductController;
use App\Http\Controllers\App\ProductImportController;
use App\Http\Controllers\App\PromotionController;
use App\Http\Controllers\App\ShopController;
use App\Http\Controllers\App\ShopSettingsController;
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
            // Module 4.10: ask Switch & Save to cancel or to change the bank account (an admin alert; nothing changes here).
            Route::post('requests', [BillingController::class, 'request'])->name('.requests.store')->middleware('throttle:10,1');
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

    // Module 4.2: products, barcodes, units, departments and categories, CSV import. Read: catalogue.view; edit: catalogue.manage.
    Route::prefix('products')->name('products.')->group(function () {
        Route::middleware('company.can:catalogue.view')->group(function () {
            Route::get('/', [ProductController::class, 'index'])->name('index');
            Route::get('categories', [CatalogueGroupController::class, 'index'])->name('groups');
        });
        Route::middleware(['company.can:catalogue.manage', 'throttle:120,1'])->group(function () {
            Route::get('create', [ProductController::class, 'create'])->name('create');
            Route::post('/', [ProductController::class, 'store'])->name('store');
            Route::put('{product}', [ProductController::class, 'update'])->name('update')->whereUlid('product');
            Route::post('{product}/archive', [ProductController::class, 'archive'])->name('archive')->whereUlid('product');
            Route::post('{product}/restore', [ProductController::class, 'restore'])->name('restore')->whereUlid('product');
            Route::post('departments', [CatalogueGroupController::class, 'storeDepartment'])->name('departments.store');
            Route::put('departments/{department}', [CatalogueGroupController::class, 'updateDepartment'])->name('departments.update')->whereUlid('department');
            Route::delete('departments/{department}', [CatalogueGroupController::class, 'destroyDepartment'])->name('departments.destroy')->whereUlid('department');
            Route::post('categories', [CatalogueGroupController::class, 'storeCategory'])->name('categories.store');
            Route::put('categories/{category}', [CatalogueGroupController::class, 'updateCategory'])->name('categories.update')->whereUlid('category');
            Route::delete('categories/{category}', [CatalogueGroupController::class, 'destroyCategory'])->name('categories.destroy')->whereUlid('category');
            Route::get('imports', [ProductImportController::class, 'index'])->name('imports.index');
            Route::get('imports/template', [ProductImportController::class, 'template'])->name('imports.template');
            Route::post('imports', [ProductImportController::class, 'store'])->name('imports.store');
            Route::get('imports/{import}', [ProductImportController::class, 'show'])->name('imports.show')->whereUlid('import');
            Route::post('imports/{import}/preview', [ProductImportController::class, 'preview'])->name('imports.preview')->whereUlid('import');
            Route::post('imports/{import}/apply', [ProductImportController::class, 'apply'])->name('imports.apply')->whereUlid('import');
        });
        Route::get('{product}', [ProductController::class, 'show'])->name('show')->whereUlid('product')->middleware('company.can:catalogue.view');
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
    // Module 4.9: till settings for every shop or one shop (§10.3). A one-shop user: their own shop only.
    Route::prefix('settings')->name('settings.')->middleware('company.can:settings.manage')->group(function () {
        Route::get('/', [ShopSettingsController::class, 'index'])->name('index');
        Route::put('/', [ShopSettingsController::class, 'update'])->name('update')->middleware('throttle:60,1');
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

    // Module 4.4: customers, their ledger across shops, statements (screen, PDF, email) and marketing consent. Read:
    // customers.view; add / edit details: customers.manage (a one-shop user may look only, CustomerRequest).
    Route::prefix('customers')->name('customers.')->group(function () {
        Route::middleware('company.can:customers.manage')->group(function () {
            Route::get('create', [CustomerController::class, 'create'])->name('create');
            Route::post('/', [CustomerController::class, 'store'])->name('store')->middleware('throttle:60,1');
            Route::put('{customer}', [CustomerController::class, 'update'])->name('update')->whereUlid('customer')->middleware('throttle:60,1');
            Route::post('{customer}/statement/email', [CustomerController::class, 'emailStatement'])->name('statement.email')->whereUlid('customer')->middleware('throttle:10,1');
        });
        Route::middleware('company.can:customers.view')->group(function () {
            Route::get('/', [CustomerController::class, 'index'])->name('index');
            Route::get('{customer}', [CustomerController::class, 'show'])->name('show')->whereUlid('customer');
            Route::get('{customer}/statement', [CustomerController::class, 'statement'])->name('statement')->whereUlid('customer');
            Route::get('{customer}/statement/pdf', [CustomerController::class, 'statementPdf'])->name('statement.pdf')->whereUlid('customer')->middleware('throttle:30,1');
        });
    });

    // Module 4.7: shops and tills (licences read only, till health), shop and business details, "Ask for more tills".
    // Read: shops.view; shop edits and requests: shops.manage; business details: business.manage (owner).
    Route::prefix('shops')->name('shops.')->middleware('company.can:shops.view')->group(function () {
        Route::get('/', [ShopController::class, 'index'])->name('index');
        Route::get('business', [ShopController::class, 'business'])->name('business');
        Route::put('business', [ShopController::class, 'updateBusiness'])->name('business.update')->middleware(['company.can:business.manage', 'throttle:30,1']);
        Route::post('requests', [ShopController::class, 'request'])->name('requests.store')->middleware(['company.can:shops.manage', 'throttle:10,1']);
        Route::get('{branch}', [ShopController::class, 'show'])->name('show')->whereUlid('branch');
        Route::put('{branch}', [ShopController::class, 'update'])->name('update')->whereUlid('branch')->middleware(['company.can:shops.manage', 'throttle:30,1']);
    });

    // Module 4.3: shop prices (a new BranchPrice row per change; "every shop" = the business price), the tills' price
    // change batches (read-only) and offers. Read: catalogue.view; change: prices.manage / promotions.manage. A one-shop
    // user changes only their own shop's prices and offers (the form requests).
    Route::prefix('prices')->name('prices.')->group(function () {
        Route::middleware('company.can:catalogue.view')->group(function () {
            Route::get('/', [PriceController::class, 'index'])->name('index');
            Route::get('changes', [PriceController::class, 'changes'])->name('changes');
            Route::get('{product}', [PriceController::class, 'show'])->name('show')->whereUlid('product');
        });
        Route::middleware(['company.can:prices.manage', 'throttle:120,1'])->group(function () {
            Route::post('{product}/shop', [PriceController::class, 'setShop'])->name('shop.store')->whereUlid('product');
            Route::post('{product}/shop/end', [PriceController::class, 'endShop'])->name('shop.end')->whereUlid('product');
            Route::post('{product}/every-shop', [PriceController::class, 'everyShop'])->name('every-shop')->whereUlid('product');
            Route::post('rows/{row}/cancel', [PriceController::class, 'cancel'])->name('rows.cancel')->whereUlid('row');
        });
    });
    Route::prefix('promotions')->name('promotions.')->group(function () {
        Route::get('/', [PromotionController::class, 'index'])->name('index')->middleware('company.can:catalogue.view');
        Route::middleware(['company.can:promotions.manage', 'throttle:120,1'])->group(function () {
            Route::get('create', [PromotionController::class, 'create'])->name('create');
            Route::post('/', [PromotionController::class, 'store'])->name('store');
            Route::put('{promotion}', [PromotionController::class, 'update'])->name('update')->whereUlid('promotion');
            Route::post('{promotion}/end', [PromotionController::class, 'end'])->name('end')->whereUlid('promotion');
        });
        Route::get('{promotion}', [PromotionController::class, 'edit'])->name('edit')->whereUlid('promotion')->middleware('company.can:catalogue.view');
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
