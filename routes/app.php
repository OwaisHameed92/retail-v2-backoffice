<?php

// Tenant portal area. Loaded under the /app prefix, `app.` name prefix and the "web" middleware group
// (see bootstrap/app.php). Every route here must sit behind `auth`, `verified` and `company`; add
// `company.can:<ability>` for anything role-restricted.

use App\Http\Controllers\App\AccountsController;
use App\Http\Controllers\App\BillingController;
use App\Http\Controllers\App\CashController;
use App\Http\Controllers\App\CatalogueGroupController;
use App\Http\Controllers\App\ComplianceController;
use App\Http\Controllers\App\CustomerController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\HeadOfficeOrderController;
use App\Http\Controllers\App\InvitationAcceptController;
use App\Http\Controllers\App\PortalInvitationController;
use App\Http\Controllers\App\PortalUserController;
use App\Http\Controllers\App\PriceController;
use App\Http\Controllers\App\ProductController;
use App\Http\Controllers\App\ProductImportController;
use App\Http\Controllers\App\ProductRecallController;
use App\Http\Controllers\App\PromotionController;
use App\Http\Controllers\App\PurchasingController;
use App\Http\Controllers\App\ReportController;
use App\Http\Controllers\App\SaleController;
use App\Http\Controllers\App\ShopController;
use App\Http\Controllers\App\ShopSettingsController;
use App\Http\Controllers\App\StaffController;
use App\Http\Controllers\App\StaffTimeController;
use App\Http\Controllers\App\StockController;
use App\Http\Controllers\App\StockTakeController;
use App\Http\Controllers\App\SupplierController;
use App\Http\Controllers\App\SwitchBranchController;
use App\Http\Controllers\App\SwitchCompanyController;
use App\Http\Controllers\App\SyncConflictController;
use App\Http\Controllers\App\TillListController;
use App\Http\Controllers\App\TransferController;
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
    // Module 5.6: staff time, read only (clock events, timesheets and payroll CSV, rota). staff.view; one-shop: their shop.
    Route::prefix('staff/time')->name('staff.time.')->middleware('company.can:staff.view')->group(function () {
        Route::get('/', [StaffTimeController::class, 'clock'])->name('clock');
        Route::get('timesheets', [StaffTimeController::class, 'timesheets'])->name('timesheets');
        Route::get('timesheets/export', [StaffTimeController::class, 'export'])->name('export')->middleware('throttle:20,1');
        Route::get('rota', [StaffTimeController::class, 'rota'])->name('rota');
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

    // Module 4.6: sales and receipts, read only (the tills' rows); CSV export streamed, or queued when large. sales.view;
    // a one-shop user sees only their shop.
    Route::prefix('sales')->name('sales.')->middleware('company.can:sales.view')->group(function () {
        Route::get('/', [SaleController::class, 'index'])->name('index');
        Route::get('export', [SaleController::class, 'export'])->name('export')->middleware('throttle:10,1');
        Route::get('exports/{export}', [SaleController::class, 'download'])->name('exports.download')->whereUlid('export')->middleware('throttle:30,1');
        Route::get('{sale}', [SaleController::class, 'show'])->name('show')->whereAlphaNumeric('sale');
    });

    // Module 5.4: cash and Z, read only (the tills' rows, day locks included). cash.view; a one-shop user: their shop.
    Route::prefix('cash')->name('cash.')->middleware('company.can:cash.view')->group(function () {
        Route::get('/', [CashController::class, 'index'])->name('index');
        Route::get('shifts/{shift}', [CashController::class, 'shift'])->name('shifts.show')->whereAlphaNumeric('shift');
        Route::get('z', [CashController::class, 'zReports'])->name('z.index');
        Route::get('z/{zReport}', [CashController::class, 'zReport'])->name('z.show')->whereAlphaNumeric('zReport');
        Route::get('banking', [CashController::class, 'banking'])->name('banking');
        Route::get('counts', [CashController::class, 'counts'])->name('counts');
        Route::get('cards', [CashController::class, 'cards'])->name('cards');
        Route::get('days', [CashController::class, 'days'])->name('days');
        Route::get('alerts', [CashController::class, 'alerts'])->name('alerts');
    });

    // Module 5.5: accounts and VAT, read only (the tills' accounts, journals, expenses, VAT returns, fixed assets).
    // accounts.view; a one-shop user: their shop.
    Route::prefix('accounts')->name('accounts.')->middleware('company.can:accounts.view')->group(function () {
        Route::get('/', [AccountsController::class, 'index'])->name('index');
        Route::get('journals', [AccountsController::class, 'journals'])->name('journals.index');
        Route::get('journals/{entry}', [AccountsController::class, 'journal'])->name('journals.show')->whereAlphaNumeric('entry');
        Route::get('trial-balance', [AccountsController::class, 'trialBalance'])->name('trial-balance');
        Route::get('profit-and-loss', [AccountsController::class, 'profitAndLoss'])->name('profit-and-loss');
        Route::get('balance-sheet', [AccountsController::class, 'balanceSheet'])->name('balance-sheet');
        Route::get('expenses', [AccountsController::class, 'expenses'])->name('expenses');
        Route::get('vat', [AccountsController::class, 'vat'])->name('vat');
        Route::get('vat/print', [AccountsController::class, 'vatPrint'])->name('vat.print');
        Route::get('vat/csv', [AccountsController::class, 'vatCsv'])->name('vat.csv')->middleware('throttle:30,1');
        Route::get('fixed-assets', [AccountsController::class, 'fixedAssets'])->name('fixed-assets');
    });

    // Module 5.1: stock (the tills' rows, read only): on hand, movements, stock takes, valuation, dates and wastage.
    // stock.view; a product's own stock levels (Product is hub-owned): stock.manage. A one-shop user sees only their shop.
    Route::prefix('stock')->name('stock.')->middleware('company.can:stock.view')->group(function () {
        Route::get('/', [StockController::class, 'index'])->name('index');
        Route::get('movements', [StockController::class, 'movements'])->name('movements');
        Route::get('valuation', [StockController::class, 'valuation'])->name('valuation');
        Route::get('expiry', [StockController::class, 'expiry'])->name('expiry');
        Route::get('takes', [StockTakeController::class, 'index'])->name('takes.index');
        Route::get('takes/{take}', [StockTakeController::class, 'show'])->name('takes.show')->whereAlphaNumeric('take');
        Route::get('products/{product}', [StockController::class, 'product'])->name('products.show')->whereAlphaNumeric('product');
        Route::put('products/{product}/levels', [StockController::class, 'updateLevels'])->name('products.levels')->whereAlphaNumeric('product')
            ->middleware(['company.can:stock.manage', 'throttle:60,1']);
    });

    // Module 5.2: purchasing, read only (the shops' orders, deliveries, invoices, credits, returns, payments, rebates,
    // supplier statements): purchasing.view; a one-shop user sees only their shop. Head-office orders for a shop:
    // purchasing.manage and every shop (HeadOfficeOrderRequest).
    Route::prefix('purchasing')->name('purchasing.')->middleware('company.can:purchasing.view')->group(function () {
        Route::middleware('company.can:purchasing.manage')->group(function () {
            Route::get('orders/create', [HeadOfficeOrderController::class, 'create'])->name('orders.create');
            Route::post('orders', [HeadOfficeOrderController::class, 'store'])->name('orders.store')->middleware('throttle:60,1');
            Route::get('orders/{order}/edit', [HeadOfficeOrderController::class, 'edit'])->name('orders.edit')->whereUlid('order');
            Route::put('orders/{order}', [HeadOfficeOrderController::class, 'update'])->name('orders.update')->whereUlid('order')->middleware('throttle:60,1');
            Route::post('orders/{order}/send', [HeadOfficeOrderController::class, 'send'])->name('orders.send')->whereUlid('order')->middleware('throttle:60,1');
            Route::post('orders/{order}/cancel', [HeadOfficeOrderController::class, 'cancel'])->name('orders.cancel')->whereUlid('order')->middleware('throttle:60,1');
        });
        Route::get('/', [PurchasingController::class, 'home'])->name('home');
        Route::get('orders/{order}', [PurchasingController::class, 'order'])->name('orders.show')->whereUlid('order');
        Route::get('statements', [PurchasingController::class, 'statements'])->name('statements.index');
        Route::get('statements/{supplier}', [PurchasingController::class, 'statement'])->name('statements.show')->whereUlid('supplier');
        Route::get('{kind}', [PurchasingController::class, 'index'])->name('index')
            ->whereIn('kind', ['orders', 'deliveries', 'invoices', 'credit-notes', 'returns', 'payments', 'rebates']);
        Route::get('{kind}/{document}', [PurchasingController::class, 'document'])->name('documents.show')
            ->whereIn('kind', ['deliveries', 'invoices', 'credit-notes', 'returns'])->whereUlid('document');
    });

    // Module 5.3: stock transfers between shops, read only (the shops own them; ownership.json has no portal draft for
    // them): transfers.view; a one-shop user sees transfers from or to their shop only. CSVs are throttled.
    Route::prefix('transfers')->name('transfers.')->middleware('company.can:transfers.view')->group(function () {
        Route::get('/', [TransferController::class, 'index'])->name('index');
        Route::get('export', [TransferController::class, 'export'])->name('export')->middleware('throttle:30,1');
        Route::get('discrepancies', [TransferController::class, 'discrepancies'])->name('discrepancies');
        Route::get('discrepancies/export', [TransferController::class, 'discrepanciesExport'])->name('discrepancies.export')->middleware('throttle:30,1');
        Route::get('{transfer}', [TransferController::class, 'show'])->name('show')->whereUlid('transfer');
    });

    // Module 5.7: compliance. Read only (the tills' rows) except product recalls, hub-owned: raised, edited and closed
    // with compliance.manage and every shop. compliance.view; a one-shop user sees their shop only.
    Route::prefix('compliance')->name('compliance.')->middleware('company.can:compliance.view')->group(function () {
        Route::get('/', [ComplianceController::class, 'index'])->name('index');
        Route::get('age-checks', [ComplianceController::class, 'ageChecks'])->name('age-checks');
        Route::get('incidents', [ComplianceController::class, 'incidents'])->name('incidents');
        Route::get('incidents/{incident}', [ComplianceController::class, 'incident'])->name('incidents.show')->whereAlphaNumeric('incident');
        Route::get('training', [ComplianceController::class, 'training'])->name('training');
        Route::get('diary', [ComplianceController::class, 'diary'])->name('diary');
        Route::get('licences', [ComplianceController::class, 'licences'])->name('licences');
        Route::get('exceptions', [ComplianceController::class, 'exceptions'])->name('exceptions');
        Route::get('recalls', [ComplianceController::class, 'recalls'])->name('recalls');
        Route::get('recalls/{recall}', [ComplianceController::class, 'recall'])->name('recalls.show')->whereAlphaNumeric('recall');
        Route::middleware(['company.can:compliance.manage', 'throttle:30,1'])->group(function () {
            Route::post('recalls', [ProductRecallController::class, 'store'])->name('recalls.store');
            Route::put('recalls/{recall}', [ProductRecallController::class, 'update'])->name('recalls.update')->whereAlphaNumeric('recall');
            Route::put('recalls/{recall}/status', [ProductRecallController::class, 'status'])->name('recalls.status')->whereAlphaNumeric('recall');
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

    // Module 4.8: reports (sales, products, refunds, discounts, VAT, payments, staff, hours, stock, shifts and Z), each
    // on screen, as CSV and printable. reports.view; a one-shop user sees only their shop (BusinessContext).
    Route::prefix('reports')->name('reports.')->middleware('company.can:reports.view')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('{report}', [ReportController::class, 'show'])->name('show');
        Route::get('{report}/export', [ReportController::class, 'export'])->name('export')->middleware('throttle:30,1');
        Route::get('{report}/print', [ReportController::class, 'print'])->name('print')->middleware('throttle:60,1');
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
